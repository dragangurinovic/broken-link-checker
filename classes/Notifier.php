<?php

require_once __DIR__ . '/../includes/db.php';

class Notifier {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function notify(int $scanId): void {
        $scan = $this->db->prepare('SELECT s.*, si.name as site_name, si.url as site_url FROM scans s JOIN sites si ON s.site_id = si.id WHERE s.id = ?');
        $scan->execute([$scanId]);
        $scan = $scan->fetch();

        if (!$scan) return;

        $onlyIfBroken = Database::getSetting('notify_only_if_broken') === '1';
        if ($onlyIfBroken && $scan['links_broken'] == 0) return;

        $brokenLinks = $this->db->prepare('SELECT source_url, target_url, http_status, status_category, link_text, link_type, error_message FROM links WHERE scan_id = ? AND status_category IN (\'broken\', \'warning\', \'blocked\') ORDER BY status_category ASC, http_status ASC');
        $brokenLinks->execute([$scanId]);
        $brokenLinks = $brokenLinks->fetchAll();

        if (Database::getSetting('notify_email_enabled') === '1') {
            $this->sendEmail($scan, $brokenLinks);
        }

        if (Database::getSetting('notify_slack_enabled') === '1') {
            $this->sendSlack($scan, $brokenLinks);
        }
    }

    private function sendEmail(array $scan, array $brokenLinks): void {
        $to = !empty($scan['notify_email']) ? $scan['notify_email'] : Database::getSetting('notify_email_to');
        if (empty($to)) return;

        $siteName = $scan['site_name'];
        $broken = $scan['links_broken'];
        $warnings = $scan['links_warning'];
        $total = $scan['links_checked'];
        $health = $scan['health_score'] !== null ? round($scan['health_score'], 1) : 'N/A';

        $subject = "[Link Checker] {$siteName}: ";
        if ($broken > 0) {
            $subject .= "{$broken} broken link" . ($broken > 1 ? 's' : '') . " found";
        } else {
            $subject .= "All links OK";
        }

        $textBody = "Broken Link Checker Report\n";
        $textBody .= "========================\n\n";
        $textBody .= "Site: {$siteName} ({$scan['site_url']})\n";
        $textBody .= "Health Score: {$health}/100\n";
        $textBody .= "Pages Crawled: {$scan['pages_crawled']}\n";
        $textBody .= "Links Checked: {$total}\n";
        $textBody .= "Broken: {$broken}\n";
        $textBody .= "Warnings: {$warnings}\n";
        $textBody .= "Duration: " . ($scan['duration_secs'] ? gmdate('H:i:s', $scan['duration_secs']) : 'N/A') . "\n\n";

        if ($broken > 0) {
            $textBody .= "See the attached CSV spreadsheet for the full list of broken links.\n\n";
        }

        $textBody .= "Full report: " . BLC_APP_URL . "?page=site-detail&site_id={$scan['site_id']}\n";

        $boundary = md5(uniqid(time()));

        $headers = "From: Broken Link Checker <noreply@investor.fm>\r\n";
        $headers .= "MIME-Version: 1.0\r\n";

        if (!empty($brokenLinks)) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

            $csvData = $this->buildBrokenLinksCsv($brokenLinks, $siteName);
            $csvFilename = 'broken-links-' . preg_replace('/[^a-z0-9]+/i', '-', $siteName) . '-' . date('Y-m-d') . '.csv';

            $body = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= $textBody . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/csv; charset=UTF-8; name=\"{$csvFilename}\"\r\n";
            $body .= "Content-Disposition: attachment; filename=\"{$csvFilename}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= chunk_split(base64_encode($csvData)) . "\r\n";
            $body .= "--{$boundary}--\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body = $textBody;
        }

        $emails = array_map('trim', explode(',', $to));
        foreach ($emails as $email) {
            $sent = mail($email, $subject, $body, $headers);
            $this->logNotification($scan['id'], 'email', $email, $subject, $sent);
        }
    }

    private function buildBrokenLinksCsv(array $brokenLinks, string $siteName): string {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Source Page', 'Broken URL', 'HTTP Status', 'Status', 'Anchor Text', 'Link Type', 'Error']);
        foreach ($brokenLinks as $link) {
            fputcsv($out, [
                $link['source_url'],
                $link['target_url'],
                $link['http_status'] ?? '',
                $link['status_category'],
                $link['link_text'] ?? '',
                $link['link_type'],
                $link['error_message'] ?? '',
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    private function sendSlack(array $scan, array $brokenLinks): void {
        $webhook = Database::getSetting('notify_slack_webhook');
        if (empty($webhook)) return;

        $siteName = $scan['site_name'];
        $broken = $scan['links_broken'];
        $health = $scan['health_score'] !== null ? round($scan['health_score'], 1) : 'N/A';
        $color = $broken > 0 ? '#ef4444' : '#22c55e';

        $text = "*{$siteName}* - Link Check Complete\n";
        $text .= "Health Score: *{$health}/100*\n";
        $text .= "Pages: {$scan['pages_crawled']} | Links: {$scan['links_checked']} | Broken: *{$broken}* | Warnings: {$scan['links_warning']}\n";

        if (!empty($brokenLinks)) {
            $text .= "\n*Top Broken Links:*\n";
            $shown = array_slice($brokenLinks, 0, 10);
            foreach ($shown as $link) {
                $status = $link['http_status'] ?? 'ERR';
                $text .= "• `{$status}` <{$link['target_url']}|" . substr($link['target_url'], 0, 60) . ">\n";
                $text .= "  _Found on:_ <{$link['source_url']}|" . substr($link['source_url'], 0, 50) . ">\n";
            }
            if (count($brokenLinks) > 10) {
                $text .= "\n_...and " . (count($brokenLinks) - 10) . " more_\n";
            }
        }

        $payload = [
            'attachments' => [
                [
                    'color' => $color,
                    'text' => $text,
                    'mrkdwn_in' => ['text'],
                    'footer' => 'Broken Link Checker',
                    'ts' => time(),
                    'actions' => [
                        [
                            'type' => 'button',
                            'text' => 'View Full Report',
                            'url' => BLC_APP_URL . '?page=site-detail&site_id=' . $scan['site_id'],
                        ],
                    ],
                ],
            ],
        ];

        $channel = Database::getSetting('notify_slack_channel');
        if (!empty($channel)) {
            $payload['channel'] = $channel;
        }

        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $success = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        $error = $success ? null : (curl_error($ch) ?: $response);
        curl_close($ch);

        $this->logNotification($scan['id'], 'slack', 'webhook', "Report: {$siteName}", $success, $error);
    }

    private function logNotification(int $scanId, string $channel, string $recipient, string $subject, bool $success, ?string $error = null): void {
        $stmt = $this->db->prepare('INSERT INTO notification_log (scan_id, channel, recipient, subject, status, error_message) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$scanId, $channel, $recipient, $subject, $success ? 'sent' : 'failed', $error]);
    }
}
