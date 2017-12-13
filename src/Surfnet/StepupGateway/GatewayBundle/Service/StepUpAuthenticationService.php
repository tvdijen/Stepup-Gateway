<?php

/**
 * Copyright 2014 SURFnet bv
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Surfnet\StepupGateway\GatewayBundle\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Psr\Log\LoggerInterface;
use Surfnet\SamlBundle\Entity\ServiceProvider;
use Surfnet\StepupBundle\Command\SendSmsChallengeCommand as StepupSendSmsChallengeCommand;
use Surfnet\StepupBundle\Command\VerifyPossessionOfPhoneCommand;
use Surfnet\StepupBundle\Service\LoaResolutionService;
use Surfnet\StepupBundle\Service\SecondFactorTypeService;
use Surfnet\StepupBundle\Service\SmsSecondFactor\OtpVerification;
use Surfnet\StepupBundle\Service\SmsSecondFactorService;
use Surfnet\StepupBundle\Value\Loa;
use Surfnet\StepupBundle\Value\PhoneNumber\InternationalPhoneNumber;
use Surfnet\StepupBundle\Value\YubikeyOtp;
use Surfnet\StepupBundle\Value\YubikeyPublicId;
use Surfnet\StepupGateway\ApiBundle\Dto\Otp as ApiOtp;
use Surfnet\StepupGateway\ApiBundle\Dto\Requester;
use Surfnet\StepupGateway\ApiBundle\Service\YubikeyService;
use Surfnet\StepupGateway\GatewayBundle\Command\SendSmsChallengeCommand;
use Surfnet\StepupGateway\GatewayBundle\Command\VerifyYubikeyOtpCommand;
use Surfnet\StepupGateway\GatewayBundle\Entity\SecondFactor;
use Surfnet\StepupGateway\GatewayBundle\Entity\SecondFactorRepository;
use Surfnet\StepupGateway\GatewayBundle\Exception\RuntimeException;
use Surfnet\StepupGateway\GatewayBundle\Service\StepUp\YubikeyOtpVerificationResult;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class StepUpAuthenticationService
{
    /**
     * @var \Surfnet\StepupBundle\Service\LoaResolutionService
     */
    private $loaResolutionService;

    /**
     * @var \Surfnet\StepupGateway\GatewayBundle\Entity\SecondFactorRepository
     */
    private $secondFactorRepository;

    /**
     * @var \Surfnet\StepupGateway\ApiBundle\Service\YubikeyService
     */
    private $yubikeyService;

    /**
     * @var \Surfnet\StepupBundle\Service\SmsSecondFactorService
     */
    private $smsService;

    /** @var InstitutionMatchingHelper */
    private $institutionMatchingHelper;

    /**
     * @var \Symfony\Component\Translation\TranslatorInterface
     */
    private $translator;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var SecondFactorTypeService
     */
    private $secondFactorTypeService;

    /**
     * @param LoaResolutionService   $loaResolutionService
     * @param SecondFactorRepository $secondFactorRepository
     * @param YubikeyService         $yubikeyService
     * @param SmsSecondFactorService $smsService
     * @param InstitutionMatchingHelper $institutionMatchingHelper
     * @param TranslatorInterface    $translator
     * @param LoggerInterface        $logger
     * @param SecondFactorTypeService $secondFactorTypeService
     */
    public function __construct(
        LoaResolutionService $loaResolutionService,
        SecondFactorRepository $secondFactorRepository,
        YubikeyService $yubikeyService,
        SmsSecondFactorService $smsService,
        InstitutionMatchingHelper $institutionMatchingHelper,
        TranslatorInterface $translator,
        LoggerInterface $logger,
        SecondFactorTypeService $secondFactorTypeService
    ) {
        $this->loaResolutionService = $loaResolutionService;
        $this->secondFactorRepository = $secondFactorRepository;
        $this->yubikeyService = $yubikeyService;
        $this->smsService = $smsService;
        $this->institutionMatchingHelper = $institutionMatchingHelper;
        $this->translator = $translator;
        $this->logger = $logger;
        $this->secondFactorTypeService = $secondFactorTypeService;
    }

    /**
     * @param string          $identityNameId
     * @param Loa             $requiredLoa
     * @return \Doctrine\Common\Collections\Collection
     */
    public function determineViableSecondFactors(
        $identityNameId,
        Loa $requiredLoa
    ) {

        $candidateSecondFactors = $this->secondFactorRepository->getAllMatchingFor(
            $requiredLoa,
            $identityNameId,
            $this->secondFactorTypeService
        );
        $this->logger->info(
            sprintf('Loaded %d matching candidate second factors', count($candidateSecondFactors))
        );

        foreach ($candidateSecondFactors as $key => $secondFactor) {
            if (!$whitelistService->contains($secondFactor->institution)) {
                $this->logger->notice(
                    sprintf(
                        'Second factor "%s" is listed for institution "%s" which is not on the whitelist',
                        $secondFactor->secondFactorId,
                        $secondFactor->institution
                    )
                );

                $candidateSecondFactors->remove($key);
            }
        }

        if ($candidateSecondFactors->isEmpty()) {
            $this->logger->alert('No suitable candidate second factors found, sending Loa cannot be given response');
        }

        return $candidateSecondFactors;
    }

    /**
     * Retrieves the required LoA for the authenticating user
     *
     * The required LoA is based on several variables. These are:
     *
     *  1. SP Requested LoA.
     *  2. The optional SP/institution specific LoA configuration
     *  3. The identity of the authenticating user (used to test if the user can provide a token for the institution
     *     he is authenticating for). Only used when the SP/institution specific LoA configuration is in play.
     *  4. The institution of the authenticating user, based on the schacHomeOrganization of the user. This is used
     *     to validate the registered tokens are actually vetted by the correct institution. Only used when the
     *     SP/institution specific LoA configuration is in play.
     *
     * These four variables determine the required LoA for the authenticating user. The possible outcomes are covered
     * by unit tests. These tests can be found in the Test folder of this bundle.
     *
     * @see: StepUpAuthenticationServiceTest::test_resolve_highest_required_loa_conbinations
     *
     * @param string $requestedLoa The SP requested LoA
     * @param $identityNameId
     * @param string $identityInstitution
     * @param ServiceProvider $serviceProvider
     * @return null|Loa
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) see https://www.pivotaltracker.com/story/show/96065350
     * @SuppressWarnings(PHPMD.NPathComplexity)      see https://www.pivotaltracker.com/story/show/96065350
     */
    public function resolveHighestRequiredLoa(
        $requestedLoa,
        $identityNameId,
        $identityInstitution,
        ServiceProvider $serviceProvider
    ) {
        $loaCandidates = new ArrayCollection();

        if ($requestedLoa) {
            $loaCandidates->add($requestedLoa);
            $this->logger->info(sprintf('Added requested Loa "%s" as candidate', $requestedLoa));
        }

        // Load the SP/institution specific LoA configuration
        $spConfiguredLoas = $serviceProvider->get('configuredLoas');

        if (array_key_exists('__default__', $spConfiguredLoas) &&
            !$loaCandidates->contains($spConfiguredLoas['__default__'])
        ) {
            $loaCandidates->add($spConfiguredLoas['__default__']);
            $this->logger->info(sprintf('Added SP\'s default Loa "%s" as candidate', $spConfiguredLoas['__default__']));
        }

        if (count($spConfiguredLoas) > 1 && is_null($identityInstitution)) {
            throw new RuntimeException(
                'SP configured LOA\'s are applicable but the authenticating user has no ' .
                'schacHomeOrganization in the assertion.'
            );
        }

        // Load the authenticating users institutions based on its vetted tokens.
        $institutionsBasedOnVettedTokens = [];
        // But only do so if there are SP/institution specific LoA restrictions
        if (!$this->hasDefaultSpConfig($spConfiguredLoas)) {
            $institutionsBasedOnVettedTokens = $this->determineInstitutionsByIdentityNameId(
                $identityNameId,
                $identityInstitution,
                $spConfiguredLoas
            );
        }

        $this->logger->info(sprintf('Loaded institution(s) for "%s"', $identityNameId));

        // Match the users institutions LoA's against the SP configured institutions
        $matchingInstitutions = $this->institutionMatchingHelper->findMatches(
            array_keys($spConfiguredLoas),
            $institutionsBasedOnVettedTokens
        );

        if (count($matchingInstitutions) > 0) {
            $this->logger->info('Found matching SP configured LoA\'s');
            foreach ($matchingInstitutions as $matchingInstitution) {
                $loaCandidates->add($spConfiguredLoas[$matchingInstitution]);
                $this->logger->info(sprintf(
                    'Added SP\'s Loa "%s" as candidate',
                    $spConfiguredLoas[$matchingInstitution]
                ));
            }
        }

        if (!count($loaCandidates)) {
            throw new RuntimeException('No Loa can be found, at least one Loa (SP default) should be found');
        }

        $actualLoas = new ArrayCollection();
        foreach ($loaCandidates as $loaDefinition) {
            $loa = $this->loaResolutionService->getLoa($loaDefinition);
            if ($loa) {
                $actualLoas->add($loa);
            }
        }

        if (!count($actualLoas)) {
            $this->logger->info(sprintf(
                'Out of "%d" candidates, no existing Loa could be found, no authentication is possible.',
                count($loaCandidates)
            ));

            return null;
        }

        /** @var \Surfnet\StepupBundle\Value\Loa $highestLoa */
        $highestLoa = $actualLoas->first();
        foreach ($actualLoas as $loa) {
            // if the current highest Loa cannot satisfy the next Loa, that must be of a higher level...
            if (!$highestLoa->canSatisfyLoa($loa)) {
                $highestLoa = $loa;
            }
        }

        $this->logger->info(
            sprintf('Out of %d candidate Loa\'s, Loa "%s" is the highest', count($loaCandidates), $highestLoa)
        );

        return $highestLoa;
    }

    /**
     * Returns whether the given Loa identifier identifies the minimum Loa, intrinsic to being authenticated via an IdP.
     *
     * @param Loa $loa
     * @return bool
     */
    public function isIntrinsicLoa(Loa $loa)
    {
        return $loa->levelIsLowerOrEqualTo(Loa::LOA_1);
    }

    /**
     * @param VerifyYubikeyOtpCommand $command
     * @return YubikeyOtpVerificationResult
     */
    public function verifyYubikeyOtp(VerifyYubikeyOtpCommand $command)
    {
        /** @var SecondFactor $secondFactor */
        $secondFactor = $this->secondFactorRepository->findOneBySecondFactorId($command->secondFactorId);

        $requester = new Requester();
        $requester->identity = $secondFactor->identityId;
        $requester->institution = $secondFactor->institution;

        $otp = new ApiOtp();
        $otp->value = $command->otp;

        $result = $this->yubikeyService->verify($otp, $requester);

        if (!$result->isSuccessful()) {
            return new YubikeyOtpVerificationResult(YubikeyOtpVerificationResult::RESULT_OTP_VERIFICATION_FAILED, null);
        }

        $otp = YubikeyOtp::fromString($command->otp);
        $publicId = YubikeyPublicId::fromOtp($otp);

        if (!$publicId->equals(new YubikeyPublicId($secondFactor->secondFactorIdentifier))) {
            return new YubikeyOtpVerificationResult(
                YubikeyOtpVerificationResult::RESULT_PUBLIC_ID_DID_NOT_MATCH,
                $publicId
            );
        }

        return new YubikeyOtpVerificationResult(YubikeyOtpVerificationResult::RESULT_PUBLIC_ID_MATCHED, $publicId);
    }

    /**
     * @param string $secondFactorId
     * @return string
     */
    public function getSecondFactorIdentifier($secondFactorId)
    {
        /** @var SecondFactor $secondFactor */
        $secondFactor = $this->secondFactorRepository->findOneBySecondFactorId($secondFactorId);

        return $secondFactor->secondFactorIdentifier;
    }

    /**
     * @return int
     */
    public function getSmsOtpRequestsRemainingCount()
    {
        return $this->smsService->getOtpRequestsRemainingCount();
    }

    /**
     * @return int
     */
    public function getSmsMaximumOtpRequestsCount()
    {
        return $this->smsService->getMaximumOtpRequestsCount();
    }

    /**
     * @param SendSmsChallengeCommand $command
     * @return bool
     */
    public function sendSmsChallenge(SendSmsChallengeCommand $command)
    {
        /** @var SecondFactor $secondFactor */
        $secondFactor = $this->secondFactorRepository->findOneBySecondFactorId($command->secondFactorId);

        $phoneNumber = InternationalPhoneNumber::fromStringFormat($secondFactor->secondFactorIdentifier);

        $stepupCommand = new StepupSendSmsChallengeCommand();
        $stepupCommand->phoneNumber = $phoneNumber;
        $stepupCommand->body = $this->translator->trans('gateway.second_factor.sms.challenge_body');
        $stepupCommand->identity = $secondFactor->identityId;
        $stepupCommand->institution = $secondFactor->institution;

        return $this->smsService->sendChallenge($stepupCommand);
    }

    /**
     * @param VerifyPossessionOfPhoneCommand $command
     * @return OtpVerification
     */
    public function verifySmsChallenge(VerifyPossessionOfPhoneCommand $command)
    {
        return $this->smsService->verifyPossession($command);
    }

    public function clearSmsVerificationState()
    {
        $this->smsService->clearSmsVerificationState();
    }

    /**
     * Tests if the authenticating user has any vetted tokens for the institution he is authenticating for.
     *
     * The user needs to have a SHO and one or more vetted tokens for this method to return any institutions.
     *
     * @param string $identityNameId Used to load vetted tokens
     * @param string $identityInstitution Used to match against the institutions of vetted tokens
     * @param array $spConfiguredLoas Used for validation (are users tokens applicable for any of the configured
     * SP/institution configured institutions?)
     * @return array
     */
    private function determineInstitutionsByIdentityNameId($identityNameId, $identityInstitution, $spConfiguredLoas)
    {
        // Load the institutions based on the nameId of the authenticating user. This information is extracted from
        // the second factors projection in the Gateway. So the institutions are based on the vetted tokens of the user.
        $institutions = $this->secondFactorRepository->getAllInstitutions($identityNameId);

        // Validations are performed on the institutions
        if (empty($institutions) && array_key_exists($identityInstitution, $spConfiguredLoas)) {
            throw new RuntimeException(
                'The authenticating user cannot provide a token for the institution it is authenticating for.'
            );
        }

        if (empty($institutions)) {
            throw new RuntimeException(
                'The authenticating user does not have any vetted tokens.'
            );
        }

        // The user has vetted tokens and it's SHO was loaded from the assertion
        if (!is_null($identityInstitution) && !empty($institutions)) {

            $institutionMatches = $this->institutionMatchingHelper->findMatches(
                $institutions,
                [$identityInstitution]
            );

            if (empty($institutionMatches)) {
                throw new RuntimeException(
                    'None of the authenticating users tokens are registered at an institution the user is currently ' .
                    'authenticating from.'
                );
            }
            // Add the SHO of the authenticating user to the list of institutions based on vetted tokens. This ensures
            // the correct LOA can be based on the organisation of the user.
            $institutions[] = $identityInstitution;
        }


        return $institutions;
    }

    private function hasDefaultSpConfig($spConfiguredLoas)
    {
        if (array_key_exists('__default__', $spConfiguredLoas) && count($spConfiguredLoas) === 1) {
            return true;
        }
        return false;
    }
}
