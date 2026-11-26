<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/core/Database.php';
require_once dirname(__DIR__) . '/core/Auth.php';
require_once dirname(__DIR__) . '/core/Audit.php';

header('Content-Type: application/json; charset=utf-8');

$auth = NuAuth::getInstance();
if (!$auth->checkAuth()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db     = NuDatabase::getInstance();
$audit  = new NuAudit();
$userId = $_SESSION['nu_user_id'] ?? '';
$action = $_GET['action'] ?? '';
$body   = (array)(json_decode((string)file_get_contents('php://input'), true) ?? []);

try {
    switch ($action) {

        // ── LIST events ────────────────────────────────────────────────────────
        case 'list':
            // Fetch all active events for the user (or shared/null user)
            $rows = $db->fetchAll(
                "SELECT * FROM nu_calendar_events
                 WHERE event_active = 1
                   AND (event_user_id = :user OR event_user_id IS NULL OR event_category = 'shared')
                 ORDER BY event_start ASC",
                [':user' => $userId]
            );
            echo json_encode(['success' => true, 'events' => $rows]);
            break;

        // ── GET single event ───────────────────────────────────────────────────
        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $event = $db->fetchOne(
                "SELECT * FROM nu_calendar_events WHERE event_id = :id AND event_active = 1
                   AND (event_user_id = :user OR event_user_id IS NULL OR event_category = 'shared')",
                [':id' => $id, ':user' => $userId]
            );
            if (!$event) {
                echo json_encode(['success' => false, 'error' => 'Event not found']);
                break;
            }
            // Check authorization
            if ($event['event_user_id'] !== null && $event['event_user_id'] != $userId && $event['event_category'] !== 'shared') {
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }
            echo json_encode(['success' => true, 'event' => $event]);
            break;

        // ── SAVE event (create or update) ──────────────────────────────────────
        case 'save':
            $title = trim((string)($body['event_title'] ?? ''));
            $start = trim((string)($body['event_start'] ?? ''));
            if ($title === '' || $start === '') {
                echo json_encode(['success' => false, 'error' => 'Title and Start Date are required']);
                break;
            }

            $end = trim((string)($body['event_end'] ?? '')) ?: null;
            $type = trim((string)($body['event_type'] ?? 'meeting'));
            $color = trim((string)($body['event_color'] ?? '#0ea5e9'));
            $desc = trim((string)($body['event_description'] ?? ''));
            $category = trim((string)($body['event_category'] ?? 'personal'));
            $rrule = trim((string)($body['event_rrule'] ?? '')) ?: null;
            $exdate = trim((string)($body['event_exdate'] ?? '')) ?: null;
            $recurrenceId = isset($body['event_recurrence_id']) ? (int)$body['event_recurrence_id'] : null;
            $originalStart = trim((string)($body['event_original_start'] ?? '')) ?: null;

            $data = [
                'event_title'          => $title,
                'event_description'    => $desc,
                'event_start'          => $start,
                'event_end'            => $end,
                'event_type'           => $type,
                'event_color'          => $color,
                'event_category'       => $category,
                'event_rrule'          => $rrule,
                'event_exdate'         => $exdate,
                'event_recurrence_id'  => $recurrenceId,
                'event_original_start' => $originalStart,
                'event_user_id'        => $userId ?: null,
                'event_active'         => 1
            ];

            $eventId = (int)($body['event_id'] ?? 0);
            if ($eventId > 0) {
                // Ensure authorized to edit
                $existing = $db->fetchOne("SELECT event_user_id, event_category FROM nu_calendar_events WHERE event_id = ?", [$eventId]);
                if (!$existing) {
                    echo json_encode(['success' => false, 'error' => 'Event not found']);
                    break;
                }
                if ($existing['event_user_id'] !== null && $existing['event_user_id'] != $userId && $existing['event_category'] !== 'shared') {
                    echo json_encode(['success' => false, 'error' => 'Forbidden']);
                    break;
                }

                unset($data['event_user_id']); // preserve creator
                $db->update('nu_calendar_events', $data, 'event_id = :id', [':id' => $eventId]);
                $audit->log('calendar_event_update', 'nu_calendar_events', $eventId);
                echo json_encode(['success' => true, 'event_id' => $eventId]);
            } else {
                $insertedId = $db->insert('nu_calendar_events', $data);
                $audit->log('calendar_event_create', 'nu_calendar_events', $insertedId);
                echo json_encode(['success' => true, 'event_id' => $insertedId]);
            }
            break;

        // ── DETACH and SAVE (save a single occurrence exception) ───────────────
        case 'detach_save':
            $masterId = (int)($body['event_recurrence_id'] ?? 0);
            $origStart = trim((string)($body['event_original_start'] ?? ''));
            $title = trim((string)($body['event_title'] ?? ''));
            $start = trim((string)($body['event_start'] ?? ''));

            if (!$masterId || $origStart === '' || $title === '' || $start === '') {
                echo json_encode(['success' => false, 'error' => 'Master ID, original start, title, and start date are required']);
                break;
            }

            // Fetch master event to verify ownership and append to exdate
            $master = $db->fetchOne("SELECT * FROM nu_calendar_events WHERE event_id = ?", [$masterId]);
            if (!$master) {
                echo json_encode(['success' => false, 'error' => 'Master event not found']);
                break;
            }
            if ($master['event_user_id'] !== null && $master['event_user_id'] != $userId && $master['event_category'] !== 'shared') {
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }

            // 1. Create the detached exception event
            $end = trim((string)($body['event_end'] ?? '')) ?: null;
            $type = trim((string)($body['event_type'] ?? 'meeting'));
            $color = trim((string)($body['event_color'] ?? '#0ea5e9'));
            $desc = trim((string)($body['event_description'] ?? ''));
            $category = trim((string)($body['event_category'] ?? 'personal'));

            $detachedData = [
                'event_title'          => $title,
                'event_description'    => $desc,
                'event_start'          => $start,
                'event_end'            => $end,
                'event_type'           => $type,
                'event_color'          => $color,
                'event_category'       => $category,
                'event_rrule'          => null,
                'event_exdate'         => null,
                'event_recurrence_id'  => $masterId,
                'event_original_start' => $origStart,
                'event_user_id'        => $master['event_user_id'], // match master owner
                'event_active'         => 1
            ];
            $detachedId = $db->insert('nu_calendar_events', $detachedData);

            // 2. Append the original date to the master's exdate list
            $exdates = [];
            if ($master['event_exdate'] !== null && trim($master['event_exdate']) !== '') {
                $exdates = array_map('trim', explode(',', $master['event_exdate']));
            }
            if (!in_array($origStart, $exdates, true)) {
                $exdates[] = $origStart;
            }
            $newExdate = implode(',', $exdates);

            $db->update(
                'nu_calendar_events',
                ['event_exdate' => $newExdate],
                'event_id = :id',
                [':id' => $masterId]
            );

            $audit->log('calendar_event_detach_save', 'nu_calendar_events', $detachedId);
            echo json_encode(['success' => true, 'detached_event_id' => $detachedId, 'master_id' => $masterId]);
            break;

        // ── DELETE single virtual instance ─────────────────────────────────────
        case 'delete_instance':
            $masterId = (int)($body['master_id'] ?? 0);
            $instanceStart = trim((string)($body['instance_start'] ?? ''));

            if (!$masterId || $instanceStart === '') {
                echo json_encode(['success' => false, 'error' => 'Master ID and instance start datetime are required']);
                break;
            }

            $master = $db->fetchOne("SELECT * FROM nu_calendar_events WHERE event_id = ?", [$masterId]);
            if (!$master) {
                echo json_encode(['success' => false, 'error' => 'Master event not found']);
                break;
            }
            if ($master['event_user_id'] !== null && $master['event_user_id'] != $userId && $master['event_category'] !== 'shared') {
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }

            // Append date to master's exdate
            $exdates = [];
            if ($master['event_exdate'] !== null && trim($master['event_exdate']) !== '') {
                $exdates = array_map('trim', explode(',', $master['event_exdate']));
            }
            if (!in_array($instanceStart, $exdates, true)) {
                $exdates[] = $instanceStart;
            }
            $newExdate = implode(',', $exdates);

            $db->update(
                'nu_calendar_events',
                ['event_exdate' => $newExdate],
                'event_id = :id',
                [':id' => $masterId]
            );

            $audit->log('calendar_event_delete_instance', 'nu_calendar_events', $masterId);
            echo json_encode(['success' => true, 'master_id' => $masterId]);
            break;

        // ── DELETE entire event / series ───────────────────────────────────────
        case 'delete':
            $id = (int)($_GET['id'] ?? 0);
            $event = $db->fetchOne("SELECT * FROM nu_calendar_events WHERE event_id = ?", [$id]);
            if (!$event) {
                echo json_encode(['success' => false, 'error' => 'Event not found']);
                break;
            }
            if ($event['event_user_id'] !== null && $event['event_user_id'] != $userId && $event['event_category'] !== 'shared') {
                echo json_encode(['success' => false, 'error' => 'Forbidden']);
                break;
            }

            // If it is a master event, also soft-delete all detached exception instances
            $db->update(
                'nu_calendar_events',
                ['event_active' => 0],
                'event_id = :id OR event_recurrence_id = :rec_id',
                [':id' => $id, ':rec_id' => $id]
            );

            $audit->log('calendar_event_delete', 'nu_calendar_events', $id);
            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    }
} catch (Throwable $e) {
    error_log('[api/calendar.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
