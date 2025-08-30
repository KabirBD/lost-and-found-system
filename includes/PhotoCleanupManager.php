<?php
/**
 * Photo Cleanup Manager
 * Handles deletion of photos from database and file system
 */

class PhotoCleanupManager {
    private $conn;
    private $uploads_dir;
    
    public function __construct($database_connection, $uploads_directory = '../uploads/') {
        $this->conn = $database_connection;
        $this->uploads_dir = rtrim($uploads_directory, '/') . '/';
    }
    
    /**
     * Process all photos in the cleanup queue
     */
    public function processCleanupQueue() {
        $deleted_count = 0;
        $error_count = 0;
        $errors = [];
        
        // Get all unprocessed photos from cleanup queue
        $query = "SELECT id, photo_url FROM photo_cleanup_queue WHERE processed = FALSE ORDER BY created_at ASC";
        $result = $this->conn->query($query);
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $queue_id = $row['id'];
                $photo_url = $row['photo_url'];
                
                try {
                    // Delete file from file system
                    if ($this->deletePhotoFile($photo_url)) {
                        $deleted_count++;
                        $this->markQueueItemProcessed($queue_id, true);
                    } else {
                        $error_count++;
                        $errors[] = "Failed to delete file: " . $photo_url;
                        $this->markQueueItemProcessed($queue_id, false);
                    }
                } catch (Exception $e) {
                    $error_count++;
                    $errors[] = "Error processing " . $photo_url . ": " . $e->getMessage();
                    $this->markQueueItemProcessed($queue_id, false);
                }
            }
        }
        
        return [
            'deleted' => $deleted_count,
            'errors' => $error_count,
            'error_messages' => $errors
        ];
    }
    
    /**
     * Delete a photo file from the file system
     */
    private function deletePhotoFile($photo_url) {
        // Extract filename from URL (handle both relative and absolute paths)
        $filename = basename($photo_url);
        $file_path = $this->uploads_dir . $filename;
        
        // Also try the full path as stored in database
        if (!file_exists($file_path)) {
            $file_path = $photo_url;
        }
        
        if (file_exists($file_path)) {
            return unlink($file_path);
        }
        
        // If file doesn't exist, consider it successfully "deleted"
        return true;
    }
    
    /**
     * Mark a cleanup queue item as processed
     */
    private function markQueueItemProcessed($queue_id, $success) {
        $stmt = $this->conn->prepare("UPDATE photo_cleanup_queue SET processed = TRUE WHERE id = ?");
        $stmt->bind_param("i", $queue_id);
        return $stmt->execute();
    }
    
    /**
     * Manually cleanup orphaned photos (can be called periodically)
     */
    public function cleanupOrphanedPhotos() {
        $deleted_count = 0;
        
        // Find photos that are not referenced by any user or item
        $query = "
            SELECT p.photo_id, p.url 
            FROM photos p 
            WHERE p.photo_id NOT IN (
                SELECT DISTINCT photo_id FROM users WHERE photo_id IS NOT NULL
                UNION 
                SELECT DISTINCT photo_id FROM items WHERE photo_id IS NOT NULL
            )
        ";
        
        $result = $this->conn->query($query);
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $photo_id = $row['photo_id'];
                $photo_url = $row['url'];
                
                // Delete from database (this will trigger the cleanup queue)
                $delete_stmt = $this->conn->prepare("DELETE FROM photos WHERE photo_id = ?");
                $delete_stmt->bind_param("i", $photo_id);
                
                if ($delete_stmt->execute()) {
                    $deleted_count++;
                }
            }
        }
        
        return $deleted_count;
    }
    
    /**
     * Delete a specific photo by ID
     */
    public function deletePhotoById($photo_id) {
        // Get photo info before deletion
        $stmt = $this->conn->prepare("SELECT url FROM photos WHERE photo_id = ?");
        $stmt->bind_param("i", $photo_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($photo = $result->fetch_assoc()) {
            // Delete from database (triggers will handle cleanup queue)
            $delete_stmt = $this->conn->prepare("DELETE FROM photos WHERE photo_id = ?");
            $delete_stmt->bind_param("i", $photo_id);
            
            return $delete_stmt->execute();
        }
        
        return false;
    }
    
    /**
     * Clean up old processed queue entries (call periodically)
     */
    public function cleanupOldQueueEntries($days_old = 7) {
        $stmt = $this->conn->prepare("
            DELETE FROM photo_cleanup_queue 
            WHERE processed = TRUE 
            AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->bind_param("i", $days_old);
        $stmt->execute();
        
        return $stmt->affected_rows;
    }
    
    /**
     * Get cleanup queue status
     */
    public function getCleanupQueueStatus() {
        $query = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN processed = FALSE THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN processed = TRUE THEN 1 ELSE 0 END) as processed
            FROM photo_cleanup_queue
        ";
        
        $result = $this->conn->query($query);
        return $result ? $result->fetch_assoc() : ['total' => 0, 'pending' => 0, 'processed' => 0];
    }
}
?>
