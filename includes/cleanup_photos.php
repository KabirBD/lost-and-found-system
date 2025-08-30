<?php
/**
 * Photo Cleanup Script
 * Run this script periodically to clean up orphaned photo files
 * Can be run manually or via cron job
 */

// Include database connection and cleanup manager
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/PhotoCleanupManager.php';

// Set execution time limit for cleanup operations
set_time_limit(300); // 5 minutes

echo "=== Lost & Found Photo Cleanup Script ===\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // Initialize cleanup manager
    $cleanup_manager = new PhotoCleanupManager($conn, __DIR__ . '/../uploads/');
    
    // 1. Get current status
    echo "1. Checking cleanup queue status...\n";
    $status = $cleanup_manager->getCleanupQueueStatus();
    echo "   Total queue entries: " . $status['total'] . "\n";
    echo "   Pending: " . $status['pending'] . "\n";
    echo "   Processed: " . $status['processed'] . "\n\n";
    
    // 2. Process cleanup queue (delete files for photos already removed from DB)
    echo "2. Processing cleanup queue...\n";
    $queue_result = $cleanup_manager->processCleanupQueue();
    echo "   Files deleted: " . $queue_result['deleted'] . "\n";
    echo "   Errors: " . $queue_result['errors'] . "\n";
    
    if (!empty($queue_result['error_messages'])) {
        echo "   Error details:\n";
        foreach ($queue_result['error_messages'] as $error) {
            echo "     - " . $error . "\n";
        }
    }
    echo "\n";
    
    // 3. Clean up orphaned photos (photos not referenced by any user or item)
    echo "3. Cleaning up orphaned photos...\n";
    $orphaned_count = $cleanup_manager->cleanupOrphanedPhotos();
    echo "   Orphaned photos removed: " . $orphaned_count . "\n\n";
    
    // 4. Process any new items added to cleanup queue
    echo "4. Processing any new queue items...\n";
    $queue_result2 = $cleanup_manager->processCleanupQueue();
    echo "   Additional files deleted: " . $queue_result2['deleted'] . "\n\n";
    
    // 5. Clean up old queue entries
    echo "5. Cleaning up old queue entries...\n";
    $old_entries = $cleanup_manager->cleanupOldQueueEntries(7);
    echo "   Old queue entries removed: " . $old_entries . "\n\n";
    
    // 6. Final status
    echo "6. Final cleanup queue status...\n";
    $final_status = $cleanup_manager->getCleanupQueueStatus();
    echo "   Total queue entries: " . $final_status['total'] . "\n";
    echo "   Pending: " . $final_status['pending'] . "\n";
    echo "   Processed: " . $final_status['processed'] . "\n\n";
    
    echo "=== Cleanup completed successfully ===\n";
    echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Cleanup failed at: " . date('Y-m-d H:i:s') . "\n";
    exit(1);
}

// If running via web browser, provide HTML output
if (isset($_SERVER['HTTP_HOST'])) {
    echo "<br><br><a href='admin/dashboard.php'>← Back to Admin Dashboard</a>";
}
?>
