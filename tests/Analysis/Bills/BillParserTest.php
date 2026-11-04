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

    /**
     * House committee video shows the bill in a serif face ("HB 746 (Henson)")
     * whose capital H Tesseract reads as two letters. The digits come through
     * correctly, so the prefix is repaired. Each string here is real OCR output
     * from a 2026 House committee frame.
     *
     * @dataProvider misreadHouseBills
     */
    public function testRepairsMisreadHouseBillPrefix(string $ocrText, string $expected): void
    {
        $this->assertSame([$expected], (new BillParser())->parse($ocrText));
    }

    public static function misreadHouseBills(): array
    {
        return [
            ['TIB 746 (Henson) PI', 'HB746'],
            ['TIB 746 (IIenson) 1)', 'HB746'],
            ['MIB 1282 (Cole. J.G.)', 'HB1282'],
            ['TB 1006 (Tran) Sel:', 'HB1006'],
            ['IIB 1723 (Price) Dey', 'HB1723'],
            ['HIB 622 (Glass) Adv:', 'HB622'],
            ['LIB 1391 (Hayes) Si', 'HB1391'],
            ['1B 605 (Willett)  Nu', 'HB605'],
            ['I IB 1006 (Tran)  eT hy', 'HB1006'],
            [')  IIB 610 (Anthony) (', 'HB610'],
        ];
    }

    public function testLeavesCorrectlyReadBillsAlone(): void
    {
        $parser = new BillParser();
        $this->assertSame(['HB746'], $parser->parse('HB 746 (Henson) 1'));
        $this->assertSame(['SB770'], $parser->parse('SB 770 (Surovell)'));
        $this->assertSame(['HB1234', 'SB567'], $parser->parse('HB 1234 SB 567'));
    }

    /** Senate floor video writes "S.B. 956"; this is Tesseract's reading of it. */
    public function testReadsBillNumbersWithPeriods(): void
    {
        $parser = new BillParser();
        $this->assertSame(['SB956'], $parser->parse('S.B. 956'));
        $this->assertSame(['SB1048'], $parser->parse('S.B.1048'));
        $this->assertSame(['HB1930'], $parser->parse('H.B. 1930'));
        $this->assertSame(['HB12', 'SB34'], $parser->parse('H. B. 12 and S. B. 34'));
    }

    public function testDoesNotInventBillsFromOrdinaryWords(): void
    {
        $parser = new BillParser();
        $this->assertSame([], $parser->parse('CLUB 12 LIBRARY 2026 ROOM 3B'));
        $this->assertSame([], $parser->parse('(TTayes) February 10, 2026'));
        $this->assertSame([], $parser->parse(''));
    }
}
