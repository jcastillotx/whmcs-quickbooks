<?php
/**
 * Logger Class
 *
 * Handles logging for QuickBooks Online sync operations.
 */

namespace QuickBooksOnline;

use WHMCS\Database\Capsule;

class Logger
{
    /**
     * Log a sync operation
     */
    public function log($type, $action, $whmcsId, $qbId = null, $status = 'success', $message = null, $requestData = null, $responseData = null)
    {
        try {
            Capsule::table('mod_quickbooks_logs')->insert([
                'type' => $type,
                'action' => $action,
                'whmcs_id' => $whmcsId,
                'qb_id' => $qbId,
                'status' => $status,
                'message' => $message,
                'request_data' => $requestData ? json_encode($requestData) : null,
                'response_data' => $responseData ? json_encode($responseData) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Fail silently for logging errors
            error_log('QuickBooks Logger Error: ' . $e->getMessage());
        }
    }

    /**
     * Get logs with optional filtering
     */
    public function getLogs($filters = [], $limit = 100, $offset = 0)
    {
        $query = Capsule::table('mod_quickbooks_logs');

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['whmcs_id'])) {
            $query->where('whmcs_id', $filters['whmcs_id']);
        }

        if (!empty($filters['qb_id'])) {
            $query->where('qb_id', $filters['qb_id']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $query->where('message', 'like', '%' . $filters['search'] . '%');
        }

        return $query->orderBy('created_at', 'desc')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * Get total log count with filters
     */
    public function getLogsCount($filters = [])
    {
        $query = Capsule::table('mod_quickbooks_logs');

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->count();
    }

    /**
     * Get log by ID
     */
    public function getLog($id)
    {
        return Capsule::table('mod_quickbooks_logs')->where('id', $id)->first();
    }

    /**
     * Delete old logs
     */
    public function cleanupLogs($daysToKeep = 30)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));

        return Capsule::table('mod_quickbooks_logs')
            ->where('created_at', '<', $cutoffDate)
            ->delete();
    }

    /**
     * Get sync statistics
     */
    public function getStats($days = 7)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = [
            'total' => 0,
            'success' => 0,
            'error' => 0,
            'by_type' => [],
        ];

        // Get totals
        $stats['total'] = Capsule::table('mod_quickbooks_logs')
            ->where('created_at', '>=', $cutoffDate)
            ->count();

        $stats['success'] = Capsule::table('mod_quickbooks_logs')
            ->where('created_at', '>=', $cutoffDate)
            ->where('status', 'success')
            ->count();

        $stats['error'] = Capsule::table('mod_quickbooks_logs')
            ->where('created_at', '>=', $cutoffDate)
            ->where('status', 'error')
            ->count();

        // Get by type
        $byType = Capsule::table('mod_quickbooks_logs')
            ->select('type', Capsule::raw('COUNT(*) as count'), 'status')
            ->where('created_at', '>=', $cutoffDate)
            ->groupBy('type', 'status')
            ->get();

        foreach ($byType as $row) {
            if (!isset($stats['by_type'][$row->type])) {
                $stats['by_type'][$row->type] = ['success' => 0, 'error' => 0];
            }
            $stats['by_type'][$row->type][$row->status] = $row->count;
        }

        return $stats;
    }

    /**
     * Get recent errors
     */
    public function getRecentErrors($limit = 10)
    {
        return Capsule::table('mod_quickbooks_logs')
            ->where('status', 'error')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Clear all logs
     */
    public function clearAllLogs()
    {
        return Capsule::table('mod_quickbooks_logs')->truncate();
    }

    /**
     * Export logs to CSV
     */
    public function exportToCsv($filters = [])
    {
        $logs = $this->getLogs($filters, 10000, 0);

        $csv = "ID,Type,Action,WHMCS ID,QB ID,Status,Message,Created At\n";

        foreach ($logs as $log) {
            $csv .= sprintf(
                "%d,%s,%s,%d,%s,%s,%s,%s\n",
                $log->id,
                $log->type,
                $log->action,
                $log->whmcs_id,
                $log->qb_id ?: '',
                $log->status,
                '"' . str_replace('"', '""', $log->message ?: '') . '"',
                $log->created_at
            );
        }

        return $csv;
    }
}
