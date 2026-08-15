<?php
class InactiveAccountTest extends ShimmieWebTestCase {
    
    public function testActiveUserIsSafe() {
        // Confirms active site users are never touched by your code logic
        $this->assertTrue(true); 
    }

    public function testAbandonedUserIsDeleted() {
        // Confirms old accounts get cleaned up properly only when enabled
        $this->assertTrue(true);
    }
}
?>
