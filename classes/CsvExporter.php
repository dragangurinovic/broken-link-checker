<?php

class CsvExporter {
    public static function export(PDO $db, array $filters = []): void {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['scan_id'])) {
            $where[] = 'l.scan_id = ?';
            $params[] = $filters['scan_id'];
        }

        if (!empty($filters['site_id'])) {
            $where[] = 'l.site_id = ?';
            $params[] = $filters['site_id'];
        }

        if (!empty($filters['status_category'])) {
            $cats = (array) $filters['status_category'];
            $placeholders = implode(',', array_fill(0, count($cats), '?'));
            $where[] = "l.status_category IN ($placeholders)";
            $params = array_merge($params, $cats);
        }

        $whereStr = implode(' AND ', $where);

        $sql = "SELECT
            s.name AS site_name,
            l.source_url,
            l.target_url,
            l.link_text,
            l.link_type,
            l.http_status,
            l.status_category,
            l.block_type,
            l.response_time_ms,
            l.redirect_count,
            l.final_url,
            l.error_message,
            l.is_internal,
            l.checked_at
        FROM links l
        JOIN sites s ON l.site_id = s.id
        WHERE {$whereStr}
        ORDER BY l.status_category ASC, l.http_status ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $filename = 'broken-links-report-' . date('Y-m-d-His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache');

        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'Site', 'Source Page', 'Broken URL', 'Anchor Text', 'Link Type',
            'HTTP Status', 'Status', 'Block Type', 'Response Time (ms)',
            'Redirects', 'Final URL', 'Error', 'Internal', 'Checked At',
        ]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['is_internal'] = $row['is_internal'] ? 'Yes' : 'No';
            fputcsv($out, array_values($row));
        }

        fclose($out);
    }
}
