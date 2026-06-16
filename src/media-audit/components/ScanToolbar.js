import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function ScanToolbar( { status, progress, total, onScan } ) {
	const isScanning = status === 'scanning';
	const pct = total > 0 ? Math.round( ( progress / total ) * 100 ) : 0;

	return (
		<div className="wp-media-audit-toolbar">
			<div className="wp-media-audit-scan-status">
				{ status === 'complete' && (
					<span>{ __( 'Index is up to date.', 'wp-media-audit' ) }</span>
				) }
				{ status === 'idle' && (
					<span>{ __( 'Index has not been built yet.', 'wp-media-audit' ) }</span>
				) }
			</div>
			<Button variant="primary" onClick={ onScan } disabled={ isScanning }>
				{ __( 'Scan Now', 'wp-media-audit' ) }
			</Button>
			{ isScanning && (
				<div className="wp-media-audit-progress">
					<div className="wp-media-audit-progress-track">
						<div
							className="wp-media-audit-progress-bar"
							style={ { width: `${ pct }%` } }
						/>
					</div>
					<span className="wp-media-audit-progress-label">
						{ sprintf(
							/* translators: 1: processed count, 2: total count */
							__( 'Scanning… %1$d / %2$d posts', 'wp-media-audit' ),
							progress,
							total
						) }
					</span>
				</div>
			) }
		</div>
	);
}
