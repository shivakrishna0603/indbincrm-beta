<?php
/**
 * Merchant training guide PDF.
 *
 * Renders the training lessons (merchant/training/lessons.php) into a PDF
 * on the fly with nothing but core PHP - the project has no PDF library, so
 * this writes a minimal PDF 1.4 file itself. It is embedded in an <iframe>
 * by merchant/training/lesson.php.
 *
 * The page is protected like the training wizard: a signed-in merchant who
 * has not finished training.
 */

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lessons.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$uid = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT role, training_completed_at FROM users WHERE id = ?");
$stmt->execute([$uid]);
$me = $stmt->fetch();

if (($me['role'] ?? '') !== 'merchant') {
    http_response_code(403);
    exit;
}
if ($me['training_completed_at']) {
    http_response_code(403);
    exit;
}

$lesson = merchant_lesson((string)($_GET['topic'] ?? ''));
if ($lesson === null) {
    http_response_code(404);
    exit;
}

/** Minimal single-file PDF writer. */
final class MerchantLessonPdf
{
    private const W   = 595;   // A4 points
    private const H   = 842;
    private const ML  = 54;    // left margin
    private const MR  = 54;    // right margin
    private const MT  = 64;    // top margin
    private const MB  = 52;    // bottom margin

    /** Approximate Helvetica widths, in thousandths of an em. */
    private const WIDTHS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
        40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
        48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556,
        56 => 556, 57 => 556, 58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556,
        64 => 1015, 65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
        72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778,
        80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556,
        96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
        104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556,
        112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722,
        120 => 500, 121 => 500, 122 => 500, 123 => 584, 124 => 278, 125 => 584, 126 => 556,
    ];

    private array $lesson;
    private int   $pageNo = 1;
    private array $pages  = [];
    private array $ops    = [];
    private float $y;

    public function __construct(array $lesson)
    {
        $this->lesson = $lesson;
        $this->y      = self::H - self::MT;
        $this->drawFirstPage();
    }

    // ------------------------------------------------------------ helpers

    private static function charW(string $ch, int $size): float
    {
        $tw = self::WIDTHS[ord($ch)] ?? 500;
        return $tw / 1000 * $size;
    }

    private static function strW(string $s, int $size): float
    {
        $w = 0.0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $w += self::charW($s[$i], $size);
        }
        return $w;
    }

    /** Normalise the text to ASCII / WinAnsi so it always renders cleanly. */
    private static function clean(string $s): string
    {
        $s = strtr($s, [
            "\xE2\x82\xB9" => 'Rs. ',   // rupee sign
            "\xE2\x80\x93" => '-',      // en dash
            "\xE2\x80\x94" => '-',      // em dash
            "\xE2\x80\x99" => "'",      // right single quote
            "\xE2\x80\x98" => "'",      // left single quote
            "\xE2\x80\x9C" => '"',      // left double quote
            "\xE2\x80\x9D" => '"',      // right double quote
            "\xC2\xB7"     => "\xB7",   // middle dot
            "\xE2\x80\xA2" => "\x95",   // bullet (WinAnsi)
            "\xC2\xA0"     => ' ',      // nbsp
        ]);
        return strtr($s, ["\\" => '\\\\', "(" => "\\(", ")" => "\\)"]);
    }

    /** Wrap text to the content width; returns the lines to lay out. */
    private function wrap(string $text, int $size): array
    {
        $avail = self::W - self::ML - self::MR;
        $lines = [];
        foreach (explode("\n", $text) as $para) {
            $words = preg_split('/\s+/', $para) ?: [];
            $cur = '';
            foreach ($words as $w) {
                if ($w === '') continue;
                $test = $cur === '' ? $w : $cur . ' ' . $w;
                if ($cur !== '' && self::strW($test, $size) > $avail) {
                    $lines[] = $cur;
                    $cur = $w;
                } else {
                    $cur = $test;
                }
            }
            if ($cur !== '') $lines[] = $cur;
        }
        return $lines;
    }

    private function ensureSpace(float $need): void
    {
        if ($this->y - $need < self::MB) {
            $this->newPage();
        }
    }

    private function finishPage(): void
    {
        $footerY = 34;
        $this->ops[] = sprintf('BT /F1 8 Tf 0.44 0.46 0.52 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                               self::ML, $footerY, self::clean('INDBIN Fintech Services LLP  |  Merchant Training Guide'));
        $right = self::W - self::MR - self::strW('Page ' . $this->pageNo, 8);
        $this->ops[] = sprintf('BT /F1 8 Tf 0.44 0.46 0.52 rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                               $right, $footerY, self::clean('Page ' . $this->pageNo));
        $this->pages[] = implode("\n", $this->ops);
    }

    private function newPage(): void
    {
        $this->finishPage();
        $this->pageNo++;
        $this->ops = [];
        $this->y   = self::H - self::MT;
    }

    private function fillRect(float $x, float $y, float $w, float $h, string $color): void
    {
        $this->ops[] = sprintf('%s %.2F %.2F %.2F %.2F re f', $color, $x, $y, $w, $h);
    }

    private function place(string $text, int $size, bool $bold, float $xFromTop, float $baselineFromTop, string $color = '0 0 0'): void
    {
        $font  = $bold ? 'F2' : 'F1';
        $baseY = self::H - $baselineFromTop;
        $this->ops[] = sprintf('BT /%s %d Tf %s rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                               $font, $size, $color, $xFromTop, $baseY, self::clean($text));
    }

    private function paragraph(string $text, int $size = 11, float $leading = 16, string $color = '0.16 0.16 0.19'): void
    {
        $lines = $this->wrap($text, $size);
        foreach ($lines as $line) {
            $this->ensureSpace($leading);
            $this->place($line, $size, false, self::ML, $this->y, $color);
            $this->y -= $leading;
        }
    }

    private function bullets(array $items, int $size = 11, int $leading = 16): void
    {
        // indent = width of the bullet gutter
        foreach ($items as $item) {
            $bulletW = self::strW("\x95  ", $size);
            $x = self::ML + $bulletW;
            $avail = self::W - self::MR - $x;
            // wrap against the narrower width manually
            $lines = [];
            $words = preg_split('/\s+/', $item) ?: [];
            $cur = '';
            foreach ($words as $w) {
                if ($w === '') continue;
                $test = $cur === '' ? $w : $cur . ' ' . $w;
                if ($cur !== '' && self::strW($test, $size) > $avail) {
                    $lines[] = $cur;
                    $cur = $w;
                } else {
                    $cur = $test;
                }
            }
            if ($cur !== '') $lines[] = $cur;

            foreach ($lines as $i => $line) {
                $this->ensureSpace($leading);
                $this->place($line, $size, false, $x, $this->y, '0.16 0.16 0.19');
                if ($i === 0) {
                    $this->place("\x95", $size, true, self::ML, $this->y, '0.91 0.45 0.08');
                }
                $this->y -= $leading;
            }
        }
    }

    private function heading(string $text, bool $pageBreak = false): void
    {
        if ($pageBreak && $this->y < self::H - self::MT + 130) {
            $this->newPage();
        }
        $this->ensureSpace(34);
        $this->y -= 8;
        $this->place($text, 14, true, self::ML, $this->y, '0.12 0.12 0.15');
        $this->y -= 6;
        $this->fillRect(self::ML, self::H - $this->y, 22, 2.5, '0.98 0.45 0.09 rg');
        $this->y -= 18;
    }

    private function drawFirstPage(): void
    {
        $this->place('MERCHANT TRAINING GUIDE', 10, true, self::ML, $this->y, '0.91 0.45 0.08');
        $this->y -= 18;

        $titleLines = $this->wrap($this->lesson['title'], 24);
        foreach ($titleLines as $line) {
            $this->ensureSpace(32);
            $this->place($line, 24, true, self::ML, $this->y, '0.10 0.10 0.12');
            $this->y -= 30;
        }
        $this->y -= 2;

        if (!empty($this->lesson['subtitle'])) {
            $sub = $this->wrap($this->lesson['subtitle'], 12);
            foreach ($sub as $line) {
                $this->ensureSpace(18);
                $this->place($line, 12, false, self::ML, $this->y, '0.42 0.45 0.50');
                $this->y -= 17;
            }
        }

        $this->y -= 8;
        $this->fillRect(self::ML, self::H - $this->y, self::W - self::ML - self::MR, 1, '0.88 0.88 0.90 rg');
        $this->y -= 24;
    }

    // ------------------------------------------------------------- layout

    private function layout(): void
    {
        foreach ($this->lesson['sections'] as $i => $block) {
            if (!empty($block['heading'])) {
                $this->heading($block['heading'], $i > 0);
            }
            if (!empty($block['body'])) {
                foreach (preg_split('/\n\s*\n/', $block['body']) ?: [] as $para) {
                    $this->paragraph($para);
                    $this->y -= 8;
                }
            }
            if (!empty($block['bullets'])) {
                $this->y -= 4;
                $this->bullets($block['bullets']);
                $this->y -= 6;
            }
        }
    }

    // -------------------------------------------------------------- output

    public function output(): string
    {
        $this->layout();
        $this->finishPage();

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = ''; // set below after counting pages
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $next = 5;
        $pageKids = [];
        foreach ($this->pages as $idx => $streamOps) {
            $pageObj  = $next++;
            $contObj  = $next++;
            $pageKids[] = "{$pageObj} 0 R";
            $objects[$pageObj] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> "
                . "/Contents %d 0 R >>",
                self::W, self::H, $contObj
            );
            $stream = $streamOps;
            $objects[$contObj] = sprintf(
                "<< /Length %d >>\nstream\n%s\nendstream",
                strlen($stream), $stream
            );
        }

        $objects[2] = sprintf(
            "<< /Type /Pages /Kids [%s] /Count %d >>",
            implode(' ', $pageKids), count($pageKids)
        );

        $out  = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $objNo => $objBody) {
            $offsets[$objNo] = strlen($out);
            $out .= "{$objNo} 0 obj\n{$objBody}\nendobj\n";
        }

        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objects) + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $out;
    }
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="indbin-' . rawurlencode($lesson['title']) . '-guide.pdf"');
header('Cache-Control: no-store');

echo (new MerchantLessonPdf($lesson))->output();