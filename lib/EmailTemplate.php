<?php

/**
 * Shared branded HTML email shell for transactional mail (password reset,
 * staff account activation). Table-based layout with inline styles only —
 * email clients strip <style> blocks and ignore most modern CSS, so this
 * intentionally avoids anything that wouldn't survive Gmail/Outlook's HTML
 * sanitizers.
 */
class EmailTemplate
{
    public static function render(string $heading, string $bodyHtml, string $ctaLabel, string $link, string $expiryNote): string
    {
        $year = date('Y');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:40px 16px;">
<tr><td align="center">
<table role="presentation" width="480" cellpadding="0" cellspacing="0" style="width:480px;max-width:100%;background-color:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.08);font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<tr>
<td style="background-color:#001c43;padding:28px 32px;text-align:center;">
<span style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.2px;">Profile<span style="color:#ed1c24;">Path</span></span><br>
<span style="font-size:11px;color:#94a3b8;letter-spacing:0.6px;text-transform:uppercase;">Career Profiling System</span>
</td>
</tr>
<tr>
<td style="padding:36px 32px 8px 32px;">
<h1 style="margin:0 0 16px 0;font-size:19px;line-height:1.4;color:#0f172a;font-weight:700;">{$heading}</h1>
<div style="font-size:14px;line-height:1.65;color:#475569;">{$bodyHtml}</div>
</td>
</tr>
<tr>
<td style="padding:8px 32px 8px 32px;text-align:center;">
<a href="{$link}" style="display:inline-block;background-color:#ed1c24;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:14px 36px;border-radius:8px;">{$ctaLabel}</a>
</td>
</tr>
<tr>
<td style="padding:20px 32px 28px 32px;">
<p style="margin:0 0 6px 0;font-size:12px;color:#94a3b8;">{$expiryNote} If the button above doesn't work, copy and paste this link into your browser:</p>
<p style="margin:0;font-size:12px;word-break:break-all;"><a href="{$link}" style="color:#ed1c24;text-decoration:none;">{$link}</a></p>
</td>
</tr>
<tr>
<td style="background-color:#f8fafc;padding:20px 32px;border-top:1px solid #e2e8f0;">
<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">Center for Guidance and Counseling &mdash; Mapúa MCL<br>
If you didn't request this, you can safely ignore this email.</p>
</td>
</tr>
</table>
<p style="margin:20px 0 0 0;font-size:11px;color:#94a3b8;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">&copy; {$year} ProfilePath. All rights reserved.</p>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Fixed-layout data report shell — used for the SHS Principal summary
     * (api/send-principal-report.php). Unlike render() above, this has no
     * single CTA link; instead it lays out one or more label/value tables,
     * in the same order and with the same labels every time, so the
     * Principal receives a consistent, comparable report rather than a
     * free-form message that varies by who wrote it.
     *
     * @param array<int,array{title:string,rows:array<int,array{0:string,1:string}>}> $sections
     */
    public static function renderReport(string $heading, string $subheading, string $introHtml, array $sections): string
    {
        $year = date('Y');
        $sectionsHtml = '';
        foreach ($sections as $section) {
            $title = htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8');
            $rowsHtml = '';
            foreach ($section['rows'] as $row) {
                $label = htmlspecialchars((string) $row[0], ENT_QUOTES, 'UTF-8');
                $value = htmlspecialchars((string) $row[1], ENT_QUOTES, 'UTF-8');
                $rowsHtml .= <<<ROW
<tr>
<td style="padding:9px 0;font-size:13px;color:#475569;border-bottom:1px solid #eef2f7;">{$label}</td>
<td style="padding:9px 0;font-size:13px;color:#0f172a;font-weight:600;text-align:right;border-bottom:1px solid #eef2f7;">{$value}</td>
</tr>
ROW;
            }
            $sectionsHtml .= <<<SEC
<tr>
<td style="padding:22px 32px 4px 32px;">
<p style="margin:0 0 10px 0;font-size:12px;font-weight:700;letter-spacing:0.4px;text-transform:uppercase;color:#19427b;">{$title}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">{$rowsHtml}</table>
</td>
</tr>
SEC;
        }

        // $heading/$subheading/$introHtml are treated as pre-formed HTML, the
        // same convention render() above uses — the caller escapes any
        // dynamic values it interpolates (see send-principal-report.php),
        // which lets a caller embed a literal entity like "&middot;" without
        // it being escaped into visible "&amp;middot;" text.

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:40px 16px;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:100%;background-color:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.08);font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
<tr>
<td style="background-color:#001c43;padding:28px 32px;text-align:center;">
<span style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.2px;">Profile<span style="color:#ed1c24;">Path</span></span><br>
<span style="font-size:11px;color:#94a3b8;letter-spacing:0.6px;text-transform:uppercase;">Career Profiling System</span>
</td>
</tr>
<tr>
<td style="padding:32px 32px 4px 32px;">
<h1 style="margin:0 0 4px 0;font-size:19px;line-height:1.4;color:#0f172a;font-weight:700;">{$heading}</h1>
<p style="margin:0 0 14px 0;font-size:12px;color:#94a3b8;">{$subheading}</p>
<div style="font-size:14px;line-height:1.65;color:#475569;">{$introHtml}</div>
</td>
</tr>
{$sectionsHtml}
<tr>
<td style="padding:24px 32px 28px 32px;">
<p style="margin:0;font-size:11px;color:#94a3b8;">This is an automatically generated summary from ProfilePath and reflects figures at the time of sending.</p>
</td>
</tr>
<tr>
<td style="background-color:#f8fafc;padding:20px 32px;border-top:1px solid #e2e8f0;">
<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">Center for Guidance and Counseling &mdash; Mapúa MCL</p>
</td>
</tr>
</table>
<p style="margin:20px 0 0 0;font-size:11px;color:#94a3b8;font-family:'Segoe UI',Helvetica,Arial,sans-serif;">&copy; {$year} ProfilePath. All rights reserved.</p>
</td></tr>
</table>
</body>
</html>
HTML;
    }
}
