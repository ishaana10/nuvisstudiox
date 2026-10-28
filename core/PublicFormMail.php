<?php
declare(strict_types=1);

/**
 * Emails sent for public form submissions: a tidy HTML summary for staff and an optional
 * confirmation for the customer. Table-based layout with inline styles so it renders in
 * Outlook, Gmail and mobile clients; a plain-text version is always included.
 */
class NuPublicFormMail
{
    private static function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Human-readable value for the email (option labels, Yes/No, dates). */
    public static function display(array $f, $v): string
    {
        if ($v === null || $v === '') return '';
        switch ($f['type']) {
            case 'checkbox':
                return $v ? 'Yes' : 'No';
            case 'select': case 'radio':
                foreach ($f['options'] as $o) if ((string)$o['value'] === (string)$v) return (string)$o['label'];
                return (string)$v;
            case 'checkbox_group':
                $map = []; foreach ($f['options'] as $o) $map[(string)$o['value']] = (string)$o['label'];
                return implode(', ', array_map(fn($x) => $map[$x] ?? $x, array_filter(explode(',', (string)$v), 'strlen')));
            case 'date':
                $t = strtotime((string)$v); return $t ? date('j M Y', $t) : (string)$v;
            case 'datetime':
                $t = strtotime((string)$v); return $t ? date('j M Y, g:i A', $t) : (string)$v;
            default:
                return (string)$v;
        }
    }

    /** Which address should receive the customer confirmation? Returns '' when none is usable. */
    public static function customerEmail(array $share, array $fields, array $clean): string
    {
        $valid = fn($x) => is_string($x) && $x !== '' && !preg_match('/[\r\n]/', $x) && filter_var($x, FILTER_VALIDATE_EMAIL);
        $chosen = trim((string)($share['fs_email_field'] ?? ''));
        if ($chosen !== '' && $valid($clean[$chosen] ?? null)) return (string)$clean[$chosen];
        foreach ($fields as $f) if ($f['type'] === 'email' && $valid($clean[$f['name']] ?? null)) return (string)$clean[$f['name']];
        foreach ($fields as $f) {
            if (preg_match('/e-?mail/i', $f['name'] . ' ' . $f['label']) && $valid($clean[$f['name']] ?? null)) return (string)$clean[$f['name']];
        }
        return '';
    }

    /**
     * @param 'staff'|'customer' $audience
     * @return array{subject:string,html:string,text:string}
     */
    public static function build(string $audience, array $share, array $form, array $fields, array $clean, string $reference, array $theme): array
    {
        global $nuConfig;
        $title  = trim((string)($share['fs_title'] ?? '')) ?: (string)$form['form_name'];
        $brand  = (string)($nuConfig['mailFromName'] ?? 'Nuvis StudioX');
        $accent = ($theme['accent'] ?? '') !== '' ? $theme['accent'] : '#2563eb';
        $when   = date('j M Y, g:i A');
        $isStaff = $audience === 'staff';

        $rows = []; $text = [];
        foreach ($fields as $f) {
            $val = self::display($f, $clean[$f['name']] ?? null);
            $text[] = $f['label'] . ': ' . ($val === '' ? '-' : $val);
            $cell = $val === '' ? '<span style="color:#9aa3b8;">&mdash;</span>' : nl2br(self::e($val));
            $rows[] = '<tr><td style="padding:10px 14px;border-bottom:1px solid #eceff6;width:38%;vertical-align:top;font-size:13px;color:#5b6785;">' . self::e($f['label'])
                    . '</td><td style="padding:10px 14px;border-bottom:1px solid #eceff6;vertical-align:top;font-size:14px;color:#14213d;">' . $cell . '</td></tr>';
        }

        if ($isStaff) {
            $subject = 'New request: ' . $title . ($reference !== '' ? ' (' . $reference . ')' : '');
            $lead = 'A new request was submitted through your public form.';
            $heading = 'New request received';
            $custom = '';
        } else {
            $subject = 'We received your request: ' . $title;
            $lead = 'Thank you! We have received your request and will be in touch.';
            $heading = 'Request received';
            $custom = trim((string)($share['fs_confirm_message'] ?? ''));
        }

        $refBlock = $reference !== ''
            ? '<div style="display:inline-block;margin:6px 0 0;padding:6px 12px;border:1px dashed #c4cbe0;border-radius:8px;font-family:Menlo,Consolas,monospace;font-size:14px;color:#14213d;">Reference: <b>' . self::e($reference) . '</b></div>'
            : '';
        $customBlock = $custom !== '' ? '<p style="margin:16px 0 0;font-size:14px;line-height:1.6;color:#14213d;">' . nl2br(self::e($custom)) . '</p>' : '';

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($subject) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f6fb;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . self::e($lead) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #e3e8f4;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">'
            . '<tr><td style="background:' . self::e($accent) . ';padding:22px 28px;">'
            . '<div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.8);">' . self::e($title) . '</div>'
            . '<div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:4px;">' . self::e($heading) . '</div></td></tr>'
            . '<tr><td style="padding:24px 28px 8px;">'
            . '<p style="margin:0;font-size:15px;line-height:1.6;color:#14213d;">' . self::e($lead) . '</p>' . $refBlock . $customBlock . '</td></tr>'
            . '<tr><td style="padding:16px 28px 8px;"><div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#5b6785;margin-bottom:8px;">' . ($isStaff ? 'Details' : 'Your answers') . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eceff6;border-radius:10px;border-collapse:separate;">' . implode('', $rows) . '</table></td></tr>'
            . '<tr><td style="padding:18px 28px 26px;font-size:12px;color:#8a94b0;line-height:1.5;">'
            . ($isStaff ? 'Submitted ' . self::e($when) . '. Reply to this email to contact the customer (if they gave an email address).' : 'Submitted ' . self::e($when) . '.')
            . '<br>' . self::e($brand) . '</td></tr></table></td></tr></table></body></html>';

        $plain = $heading . "\n" . $title . "\n\n" . $lead . "\n"
               . ($reference !== '' ? 'Reference: ' . $reference . "\n" : '')
               . ($custom !== '' ? "\n" . $custom . "\n" : '')
               . "\n" . implode("\n", $text) . "\n\nSubmitted " . $when . "\n" . $brand . "\n";

        return ['subject' => $subject, 'html' => $html, 'text' => $plain];
    }
}
