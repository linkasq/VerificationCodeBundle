<?php

namespace Creonit\VerificationCodeBundle;


use Creonit\VerificationCodeBundle\Context\CodeContext;
use Creonit\VerificationCodeBundle\Event\CreateCodeEvent;
use Creonit\VerificationCodeBundle\Event\VerificationCodeEvent;
use Creonit\VerificationCodeBundle\Event\VerificationEvents;
use Creonit\VerificationCodeBundle\Exception\UnknownScopeException;
use Creonit\VerificationCodeBundle\Generator\CodeGeneratorInterface;
use Creonit\VerificationCodeBundle\Repository\VerificationCodeRepositoryInterface;
use Creonit\VerificationCodeBundle\Scope\VerificationScopeInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class CodeManager
{
    /**
     * @var CodeGeneratorInterface
     */
    protected $codeGenerator;

    /**
     * @var VerificationScopeInterface[]
     */
    protected $scopes = [];

    /**
     * @var VerificationCodeRepositoryInterface
     */
    protected $codeRepository;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    public function __construct(CodeGeneratorInterface $codeGenerator, VerificationCodeRepositoryInterface $codeRepository, EventDispatcherInterface $eventDispatcher)
    {
        $this->codeGenerator = $codeGenerator;
        $this->codeRepository = $codeRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function addScope(VerificationScopeInterface $scope)
    {
        $this->scopes[$scope->getName()] = $scope;
        return $this;
    }

    public function getScope(string $scopeName)
    {
        if (!isset($this->scopes[$scopeName])) {
            throw new UnknownScopeException($scopeName);
        }

        return $this->scopes[$scopeName];
    }

    public function createCode(CodeContext $context)
    {
        $scope = $this->getScope($context->getScope());
        $this->codeRepository->deactivate($context);

        $context
            ->setCode($scope->generateCode($context->getKey()))
            ->setExpiredAfter($scope->getMaxAge());

        $code = $this->codeRepository->create($context);

        $this->eventDispatcher->dispatch(new CreateCodeEvent($code), VerificationEvents::CREATE_CODE);

        $this->codeRepository->save($code);

        return $code;
    }

    public function getNextAttemptTime(CodeContext $context): int
    {
        $code = $this->codeRepository->findByContext($context);

        if (!$code) {
            return 0;
        }

        $nextAttemptTime = 0;
        $createdAt = $code->getCreatedAt();

        if ($createdAt instanceof \DateTimeInterface) {
            $scope = $this->getScope($context->getScope());
            $attemptTime = $scope->getGenerationAttemptTime();

            $nextAttemptTime = ($createdAt->getTimestamp() + $attemptTime) - time();
        }

        return $nextAttemptTime > 0 ? $nextAttemptTime : 0;
    }

    public function verificationCode(CodeContext $context)
    {
        if (!$context->getCode()) {
            return false;
        }

        $context->setActive(true);

        if (!$code = $this->codeRepository->findByContext($context)) {
            return false;
        }

        $code->setVerified(true);

        $this->eventDispatcher->dispatch(new VerificationCodeEvent($code), VerificationEvents::VERIFICATION_CODE);

        $this->codeRepository->save($code);

        return true;
    }
}