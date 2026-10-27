<?php

namespace tests\unit;

use app\behaviors\AuditBehavior;
use PHPUnit\Framework\TestCase;

class AuditBehaviorTest extends TestCase
{
    public function testLooseComparison(): void
    {
        $this->assertTrue(AuditBehavior::same('3', 3));
        $this->assertTrue(AuditBehavior::same(null, ''));
        $this->assertTrue(AuditBehavior::same(true, 1));
        $this->assertTrue(AuditBehavior::same('1.0', 1));
        $this->assertFalse(AuditBehavior::same('3', 4));
        $this->assertFalse(AuditBehavior::same(null, 'x'));
        $this->assertTrue(AuditBehavior::same(['a', 'b'], ['a', 'b']));
        $this->assertFalse(AuditBehavior::same(['a'], ['a', 'b']));
    }
}
