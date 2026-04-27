<?php

declare(strict_types=1);

namespace SybaseORM\Tests\Exception;

use PHPUnit\Framework\TestCase;
use SybaseORM\Exception\TransactionException;

/**
 * Tests for TransactionException factory methods.
 */
final class TransactionExceptionFactoryTest extends TestCase
{
    public function testNoActiveTransaction(): void
    {
        $ex = TransactionException::noActiveTransaction('commit');

        $this->assertInstanceOf(TransactionException::class, $ex);
        $this->assertStringContainsString('commit', $ex->getMessage());
        $this->assertStringContainsString('no active transaction', $ex->getMessage());
    }

    public function testAlreadyActive(): void
    {
        $ex = TransactionException::alreadyActive();

        $this->assertInstanceOf(TransactionException::class, $ex);
        $this->assertStringContainsString('already active', $ex->getMessage());
    }
}
