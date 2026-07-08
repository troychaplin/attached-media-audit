<?php
namespace Attached_Media_Audit;

use Attached_Media_Audit\DB\Index_Table;
use Attached_Media_Audit\Scanner\Batch_Runner;

class Activator {

	public static function activate(): void {
		Index_Table::create();
		Batch_Runner::schedule();
	}
}
