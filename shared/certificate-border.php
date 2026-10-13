<?php
declare(strict_types=1);

/**
 * Simple multi-layer certificate border shared by screen/print and mPDF.
 * Screen/print: nested CSS borders. PDF: same geometry drawn with Rect()
 * so all four sides (including bottom) keep equal gaps.
 */

/** Page edge → outer border (all sides). */
function certificateOuterMarginMm(): float
{
    return 14.0;
}

/** Inner ring → content (all sides). */
function certificateInnerMarginMm(): float
{
    return 9.0;
}

/**
 * Space from outer border edge to content (rings + inner margin).
 * Outer 0.7+1.4 + gold 0.45+1.4 + double ≈1.2 + inner margin.
 */
function certificateContentInsetFromOuterMm(): float
{
    return 2.1 + 1.85 + 1.2 + certificateInnerMarginMm();
}

function certificateBorderCss(string $mode = 'html'): string
{
    $outer = certificateOuterMarginMm();
    $inner = certificateInnerMarginMm();
    // A4 content box height after outer sheet pad (used as frame min-height).
    $frameMin = 297.0 - (2 * $outer);

    if ($mode === 'pdf') {
        // Borders painted by certificateBorderPaintPdfFrames(); pad clears rings + inner margin.
        $pad = certificateContentInsetFromOuterMm();

        return <<<CSS
.cert-frame {
  box-sizing: border-box;
  padding: {$pad}mm;
  background: #fff;
  color: #000;
}
.cert-ring-gold {
  box-sizing: border-box;
  padding: 0;
  background: #fff;
}
.cert-ring-inner {
  box-sizing: border-box;
  padding: 0;
  background: #fff;
}
CSS;
    }

    return <<<CSS
.cert-frame {
  position: relative;
  box-sizing: border-box;
  border: 0.7mm solid #1e3a5f;
  padding: 1.4mm;
  height: 100%;
  min-height: {$frameMin}mm;
  display: flex;
  flex-direction: column;
  background: #fff;
  color: #000;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.cert-ring-gold {
  position: relative;
  box-sizing: border-box;
  border: 0.45mm solid #c4a35a;
  padding: 1.4mm;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-height: 0;
  background: #fff;
}
.cert-ring-inner {
  position: relative;
  box-sizing: border-box;
  border: 0.55mm double #1e3a5f;
  padding: {$inner}mm;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-height: 0;
  background: #fff;
}
CSS;
}

function certificateBorderOpenHtml(string $mode = 'html'): string
{
    return '<div class="cert-frame"><div class="cert-ring-gold"><div class="cert-ring-inner">';
}

function certificateBorderCloseHtml(string $mode = 'html'): string
{
    return '</div></div></div>';
}

/**
 * Draw navy → gold → navy-double frame inside page margins (equal on all sides).
 * Geometry matches screen CSS rings.
 */
function certificateBorderPaintPdfFrames(\Mpdf\Mpdf $mpdf): void
{
    $left = (float) $mpdf->lMargin;
    $top = (float) $mpdf->tMargin;
    $width = (float) $mpdf->w - (float) $mpdf->lMargin - (float) $mpdf->rMargin;
    $height = (float) $mpdf->h - (float) $mpdf->tMargin - (float) $mpdf->bMargin;

    // Outer navy — matches border: 0.7mm solid #1e3a5f
    $mpdf->SetDrawColor(30, 58, 95);
    $mpdf->SetLineWidth(0.7);
    $mpdf->Rect($left, $top, $width, $height);

    // Gold — after outer border+pad (0.7+1.4=2.1)
    $goldInset = 2.1;
    $mpdf->SetDrawColor(196, 163, 90);
    $mpdf->SetLineWidth(0.45);
    $mpdf->Rect(
        $left + $goldInset,
        $top + $goldInset,
        $width - (2 * $goldInset),
        $height - (2 * $goldInset)
    );

    // Inner double navy — after gold border+pad (2.1+0.45+1.4=3.95)
    $innerOuter = 3.95;
    $innerInner = 4.55;
    $mpdf->SetDrawColor(30, 58, 95);
    $mpdf->SetLineWidth(0.4);
    $mpdf->Rect(
        $left + $innerOuter,
        $top + $innerOuter,
        $width - (2 * $innerOuter),
        $height - (2 * $innerOuter)
    );
    $mpdf->SetLineWidth(0.35);
    $mpdf->Rect(
        $left + $innerInner,
        $top + $innerInner,
        $width - (2 * $innerInner),
        $height - (2 * $innerInner)
    );
}
