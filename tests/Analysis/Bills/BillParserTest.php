<?php

namespace RichmondSunlight\VideoProcessor\Tests\Analysis\Bills;

use PHPUnit\Framework\TestCase;
use RichmondSunlight\VideoProcessor\Analysis\Bills\BillParser;

class BillParserTest extends TestCase
{
    public function testExtractsBillNumbers(): void
    {
        $parser = new BillParser();
        $bills = $parser->parse('Discussing HB 1234 and SB567');
        $this->assertSame(['HB1234', 'SB567'], $bills);
    }
}
