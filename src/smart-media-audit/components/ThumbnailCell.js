export default function ThumbnailCell( { item } ) {
	if ( item.thumbnail_url ) {
		return (
			<img
				src={ item.thumbnail_url }
				alt=""
				width={ 60 }
				height={ 60 }
				className="wp-smart-media-audit-thumb"
			/>
		);
	}
	return (
		<span
			className="dashicons dashicons-media-default wp-smart-media-audit-thumb-icon"
			aria-hidden="true"
		/>
	);
}
