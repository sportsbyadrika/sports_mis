<?php
/**
 * Shared helpers for institution financial snapshot and receipt reporting.
 */

declare(strict_types=1);

if (!function_exists('get_institution_financial_snapshot')) {
    /**
     * Build the financial snapshot for a specific institution within an event.
     */
    function get_institution_financial_snapshot(mysqli $db, int $event_id, int $institution_id): array
    {
        $snapshot = [
            'participant_count' => 0,
            'participant_event_count' => 0,
            'participant_fees' => 0.0,
            'team_entry_count' => 0,
            'team_entry_fees' => 0.0,
            'institution_event_count' => 0,
            'institution_event_fees' => 0.0,
            'fund_pending' => 0.0,
            'fund_approved' => 0.0,
        ];

        $stmt = $db->prepare(
            "SELECT
                COUNT(DISTINCT pe.participant_id) AS participant_count,
                COUNT(DISTINCT CASE WHEN em.event_type = 'Individual' THEN pe.event_master_id END) AS participant_event_count,
                COALESCE(SUM(pe.fees), 0) AS total_fees
            FROM participant_events pe
            JOIN participants p ON p.id = pe.participant_id
            JOIN event_master em ON em.id = pe.event_master_id
            WHERE p.institution_id = ?
                AND p.status IN ('submitted', 'approved')
                AND p.event_id = ?"
        );
        $stmt->bind_param('ii', $institution_id, $event_id);
        $stmt->execute();
        $stmt->bind_result($participant_count, $participant_event_count, $participant_fees);
        if ($stmt->fetch()) {
            $snapshot['participant_count'] = (int) $participant_count;
            $snapshot['participant_event_count'] = (int) $participant_event_count;
            $snapshot['participant_fees'] = (float) $participant_fees;
        }
        $stmt->close();

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS entry_count, COALESCE(SUM(em.fees), 0) AS total_fees
            FROM team_entries te
            JOIN event_master em ON em.id = te.event_master_id
            WHERE te.institution_id = ?
                AND te.status IN ('pending', 'approved')
                AND em.event_id = ?"
        );
        $stmt->bind_param('ii', $institution_id, $event_id);
        $stmt->execute();
        $stmt->bind_result($team_entry_count, $team_entry_fees);
        if ($stmt->fetch()) {
            $snapshot['team_entry_count'] = (int) $team_entry_count;
            $snapshot['team_entry_fees'] = (float) $team_entry_fees;
        }
        $stmt->close();

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS registration_count, COALESCE(SUM(em.fees), 0) AS total_fees
            FROM institution_event_registrations ier
            JOIN event_master em ON em.id = ier.event_master_id
            WHERE ier.institution_id = ?
                AND ier.status IN ('pending', 'approved')
                AND em.event_id = ?"
        );
        $stmt->bind_param('ii', $institution_id, $event_id);
        $stmt->execute();
        $stmt->bind_result($institution_event_count, $institution_event_fees);
        if ($stmt->fetch()) {
            $snapshot['institution_event_count'] = (int) $institution_event_count;
            $snapshot['institution_event_fees'] = (float) $institution_event_fees;
        }
        $stmt->close();

        $stmt = $db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN amount ELSE 0 END), 0) AS approved_amount
            FROM fund_transfers
            WHERE event_id = ? AND institution_id = ?"
        );
        $stmt->bind_param('ii', $event_id, $institution_id);
        $stmt->execute();
        $stmt->bind_result($fund_pending, $fund_approved);
        if ($stmt->fetch()) {
            $snapshot['fund_pending'] = (float) $fund_pending;
            $snapshot['fund_approved'] = (float) $fund_approved;
        }
        $stmt->close();

        $total_fee_due = $snapshot['participant_fees'] + $snapshot['team_entry_fees'] + $snapshot['institution_event_fees'];
        $fund_total = $snapshot['fund_pending'] + $snapshot['fund_approved'];
        $balance = max($total_fee_due - $snapshot['fund_approved'], 0.0);

        $snapshot['total_fee_due'] = $total_fee_due;
        $snapshot['fund_total'] = $fund_total;
        $snapshot['balance'] = $balance;
        $snapshot['dues_cleared'] = $total_fee_due <= $snapshot['fund_approved'];
        $snapshot['fee_breakdown'] = [
            [
                'label' => 'Participant Fees',
                'counts' => [
                    ['value' => $snapshot['participant_count'], 'label' => 'Participants'],
                    ['value' => $snapshot['participant_event_count'], 'label' => 'Events'],
                ],
                'amount' => $snapshot['participant_fees'],
                'link' => 'event_staff_report_institution_participants.php?report=individual&institution_id=' . $institution_id,
            ],
            [
                'label' => 'Team Entry Fees',
                'counts' => [
                    ['value' => $snapshot['team_entry_count'], 'label' => 'Teams'],
                ],
                'amount' => $snapshot['team_entry_fees'],
            ],
            [
                'label' => 'Institution Event Fees',
                'counts' => [
                    ['value' => $snapshot['institution_event_count'], 'label' => 'Events'],
                ],
                'amount' => $snapshot['institution_event_fees'],
            ],
        ];

        return $snapshot;
    }
}
