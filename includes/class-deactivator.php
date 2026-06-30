<?php
namespace Smart_Media_Audit;

use Smart_Media_Audit\Scanner\Batch_Runner;

class Deactivator {

	public static function deactivate(): void {
		Batch_Runner::unschedule();
		delete_transient( Batch_Runner::CURSOR_KEY );
		// Clear progress so a mid-scan deactivation doesn't leave the admin page
		// showing a frozen "scanning" bar on reactivation.
		delete_option( Batch_Runner::PROGRESS_KEY );
	}
}
