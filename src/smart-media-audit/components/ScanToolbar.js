import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function ScanToolbar( { status, progress, total, onScan, onClear } ) {
	const isScanning = status === 'scanning';
	const pct = total > 0 ? Math.round( ( progress / total ) * 100 ) : 0;

	return (
		<div className="wp-smart-media-audit-toolbar">
			<div className="wp-smart-media-audit-scan-status">
				{ status === 'complete' && (
					<span>{ __( 'Index is up to date.', 'smart-media-audit' ) }</span>
				) }
				{ status === 'idle' && (
					<span>{ __( 'Index has not been built yet.', 'smart-media-audit' ) }</span>
				) }
			</div>
			<Button variant="primary" onClick={ onScan } disabled={ isScanning }>
				{ __( 'Scan Now', 'smart-media-audit' ) }
			</Button>
			<Button variant="secondary" isDestructive onClick={ onClear } disabled={ isScanning }>
				{ __( 'Clear Index', 'smart-media-audit' ) }
			</Button>
			{ isScanning && (
				<div className="wp-smart-media-audit-progress">
					<div className="wp-smart-media-audit-progress-track">
						<div
							className="wp-smart-media-audit-progress-bar"
							style={ { width: `${ pct }%` } }
						/>
					</div>
					<span className="wp-smart-media-audit-progress-label">
						{ sprintf(
							/* translators: 1: processed count, 2: total count */
							__( 'Scanning… %1$d / %2$d posts', 'smart-media-audit' ),
							progress,
							total
						) }
					</span>
				</div>
			) }
		</div>
	);
}
