<?php

namespace App\Mail;

use App\Models\FacultyScheduleEmail;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The "CLASSLY — Faculty Teaching Schedule" email (spec section 6),
 * with the schedule PDF attached (spec section 7). Requires
 * barryvdh/laravel-dompdf (composer require barryvdh/laravel-dompdf) —
 * Classly does not have a PDF library installed yet; the "Print"
 * feature elsewhere in Reports uses the browser's print dialog, not a
 * server-generated PDF, so this is a new dependency for this feature
 * only.
 */
class FacultyScheduleMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public FacultyScheduleEmail $record) {}

    public function envelope(): Envelope
    {
        $term = $this->record->academicTerm;
        $termLabel = trim(($term->schoolYear?->name ?? '').' '.($term->semester?->name ?? ''));
        $prefix = $this->record->email_type === 'updated' ? 'UPDATED Faculty Schedule' : 'Faculty Teaching Schedule';

        return new Envelope(
            // Explicit From required: this mail is sent via the
            // "gmail" mailer (see SendFacultyScheduleEmailJob), and
            // Gmail's SMTP servers require the From address to match
            // the authenticated account — the app's default
            // MAIL_FROM_ADDRESS (hello@classly.local) would be
            // rejected/bounced here.
            from: new \Illuminate\Mail\Mailables\Address(env('GMAIL_USERNAME'), env('MAIL_FROM_NAME', config('app.name'))),
            subject: "CLASSLY — {$prefix} | {$termLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.email-faculty-schedule',
            with: [
                'record' => $this->record,
                'faculty' => $this->record->faculty,
                'term' => $this->record->academicTerm,
                'isUpdate' => $this->record->email_type === 'updated',
            ],
        );
    }

    public function attachments(): array
    {
        $schoolName = app(SettingsService::class)->group('general')['general.school_name']
            ?: config('app.name', 'Classly');

        $pdf = Pdf::loadView('pdf.pdf-faculty-schedule', [
            'record' => $this->record,
            'faculty' => $this->record->faculty,
            'term' => $this->record->academicTerm,
            'rows' => $this->record->schedule_snapshot ?? [],
            'schoolName' => $schoolName,
            // Always the bundled public/logo.png, embedded as a base64
            // data URI (DomPDF can't reliably fetch a URL, and needs a
            // static asset it can process through GD either way).
            'schoolLogoDataUri' => $this->logoDataUri(),
        ])->setPaper('a4', 'portrait');

        return [
            \Illuminate\Mail\Mailables\Attachment::fromData(
                fn () => $pdf->output(),
                $this->record->pdf_filename ?? 'faculty-schedule.pdf',
            )->withMime('application/pdf'),
        ];
    }

    /**
     * Embeds public/logo.png as a base64 data URI. Note: DomPDF still
     * needs the PHP GD extension enabled to process ANY embedded
     * raster image (PNG/JPG) at render time — this is unavoidable
     * regardless of which image file is used, so GD must be enabled
     * in php.ini for the PDF attachment to render at all.
     */
    private function logoDataUri(): ?string
    {
        $path = public_path('logo.png');

        if (! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode(file_get_contents($path));
    }
}