<?php

use App\Services\ProductionQuantity;
use CodeIgniter\Test\CIUnitTestCase;

final class ProductionQuantityTest extends CIUnitTestCase
{
    public function testRejectReducesGoodQuantity(): void
    {
        $this->assertSame(97, ProductionQuantity::good(100, 3));
    }

    public function testRemainingUsesGoodNotGross(): void
    {
        $this->assertSame(3, ProductionQuantity::remaining(100, 97));
        $this->assertFalse(ProductionQuantity::reached(100, 97));
    }

    public function testProductionCompletesOnlyWhenGoodReachesTarget(): void
    {
        $this->assertTrue(ProductionQuantity::reached(100, 100));
        $this->assertSame(0, ProductionQuantity::remaining(100, 100));
    }
}
