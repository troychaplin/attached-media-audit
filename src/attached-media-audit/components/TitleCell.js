import { __ } from '@wordpress/i18n';

export default function TitleCell( { item, onDelete } ) {
	return (
		<div className="wp-attached-media-audit-title-cell">
			<strong>{ item.title }</strong>
			<div className="wp-attached-media-audit-row-actions">
				<span>
					<a href={ item.edit_url }>{ __( 'Edit', 'attached-media-audit' ) }</a>
				</span>
				<span className="wp-attached-media-audit-row-actions__sep"> | </span>
				<span>
					<a
						href="#"
						className="submitdelete"
						onClick={ ( e ) => {
							e.preventDefault();
							onDelete( item );
						} }
					>
						{ __( 'Delete Permanently', 'attached-media-audit' ) }
					</a>
				</span>
				<span className="wp-attached-media-audit-row-actions__sep"> | </span>
				<span>
					<a href={ item.file_url } target="_blank" rel="noreferrer">
						{ __( 'View', 'attached-media-audit' ) }
					</a>
				</span>
				<span className="wp-attached-media-audit-row-actions__sep"> | </span>
				<span>
					<a href={ item.file_url } download>
						{ __( 'Download file', 'attached-media-audit' ) }
					</a>
				</span>
			</div>
		</div>
	);
}
