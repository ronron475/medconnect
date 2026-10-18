<?php
/**
 * Build a SOAP PDF from the note values medConnect is saving.
 * The provider account name is an attestation. It is not a cryptographic signature.
 */

require_once dirname(__DIR__, 2) . '/lib/fpdf/fpdf.php';

class ClinicalNoteSoapPdf extends FPDF
{
    public function Header()
    {
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 6, 'medConnect SOAP note', 0, 1, 'L');
        $this->SetFont('Arial', '', 8);
        $this->MultiCell(0, 4, 'Attested through medConnect by the provider account named in this document. This is not a cryptographic digital signature.');
        $this->Ln(1);
    }

    public function Footer()
    {
        $this->SetY(-12);
        $this->SetFont('Arial', '', 8);
        $this->Cell(0, 6, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
    }
}

function clinical_note_pdf_encode(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $text);
    // U+20B1 is not in Windows-1252. Keep it visible as PHP instead of dropping it.
    $text = preg_replace('/\x{20B1}\s*/u', 'PHP ', $text) ?? $text;
    if ($text === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted === false) {
            $converted = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
        }
        if (is_string($converted)) {
            return $converted;
        }
    }
    if (function_exists('mb_convert_encoding')) {
        $latin = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        if (is_string($latin)) {
            return $latin;
        }
    }

    return preg_replace('/[^\x09\x0A\x20-\x7E]/', '?', $text) ?? '';
}

function clinical_note_pdf_provider_label(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '') {
        return '';
    }
    if (!preg_match('/^dr\.?\s+/i', $name)) {
        return 'Dr. ' . $name;
    }

    return $name;
}

function clinical_note_pdf_time_label(string $signedAt): string
{
    $signedAt = trim($signedAt);
    $ts = $signedAt === '' ? false : strtotime($signedAt);
    if ($ts === false) {
        return $signedAt;
    }

    return date('F j, Y g:i A', $ts);
}

/**
 * @param array{
 *   consultation_id?:int,
 *   subjective?:string,
 *   objective?:string,
 *   assessment?:string,
 *   plan?:string,
 *   diagnosis?:string,
 *   prescription?:string,
 *   provider_name?:string,
 *   signed_at?:string,
 *   preview?:bool
 * } $document
 */
function clinical_note_pdf_render(array $document): string
{
    $consultationId = (int) ($document['consultation_id'] ?? 0);
    if ($consultationId <= 0) {
        return '';
    }

    $preview = !empty($document['preview']);
    $provider = clinical_note_pdf_provider_label((string) ($document['provider_name'] ?? ''));
    $when = clinical_note_pdf_time_label((string) ($document['signed_at'] ?? ''));
    $prescription = trim((string) ($document['prescription'] ?? ''));

    try {
        $pdf = new ClinicalNoteSoapPdf();
        $pdf->SetCompression(false);
        $pdf->AliasNbPages();
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->SetMargins(15, 18, 15);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 14);
        $pdf->MultiCell(0, 7, clinical_note_pdf_encode($preview ? 'Preview — not finalized' : 'Finalized SOAP note'));
        $pdf->Ln(1);
        $pdf->SetFont('Arial', '', 10);
        if ($preview) {
            $pdf->MultiCell(0, 5, 'This preview is not stored and does not finalize the consultation.');
        } else {
            $pdf->MultiCell(0, 5, 'This document was generated from the SOAP note finalized in medConnect. The provider account below attested the note. This is not a cryptographic digital signature.');
        }
        $pdf->Ln(2);

        $pdf->SetFont('Arial', '', 11);
        $pdf->MultiCell(0, 6, clinical_note_pdf_encode('Consultation reference: ' . $consultationId));
        $pdf->MultiCell(0, 6, clinical_note_pdf_encode('Provider account: ' . ($provider !== '' ? $provider : 'Not available')));
        $pdf->MultiCell(0, 6, clinical_note_pdf_encode(($preview ? 'Preview generated: ' : 'Attested: ') . ($when !== '' ? $when : 'Not available')));
        $pdf->Ln(3);

        $sections = [
            'Subjective' => (string) ($document['subjective'] ?? ''),
            'Objective' => (string) ($document['objective'] ?? ''),
            'Assessment' => (string) ($document['assessment'] ?? ''),
            'Plan' => (string) ($document['plan'] ?? ''),
            'Final diagnosis (ICD-10)' => (string) ($document['diagnosis'] ?? ''),
        ];
        foreach ($sections as $title => $body) {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->MultiCell(0, 6, clinical_note_pdf_encode($title));
            $pdf->SetFont('Arial', '', 11);
            $value = trim($body);
            $pdf->MultiCell(0, 5.5, clinical_note_pdf_encode($value !== '' ? $value : 'Not recorded.'));
            $pdf->Ln(2);
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->MultiCell(0, 6, 'Prescription');
        $pdf->SetFont('Arial', '', 11);
        if ($prescription === '') {
            $pdf->MultiCell(0, 5.5, 'No prescription was recorded on this note.');
        } else {
            $pdf->MultiCell(0, 5.5, clinical_note_pdf_encode($prescription));
            $pdf->Ln(1);
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->MultiCell(0, 4.5, 'This prescription text is part of the SOAP note. A verification QR, when issued, is separate and does not open this PDF.');
        }

        $bytes = $pdf->Output('S');
    } catch (Throwable $e) {
        return '';
    }

    if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
        return '';
    }

    return $bytes;
}
