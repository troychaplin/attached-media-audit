export default function ThumbnailCell( { item } ) {
	if ( item.thumbnail_url ) {
		return (
			<img
				src={ item.thumbnail_url }
				alt=""
				width={ 60 }
				height={ 60 }
				className="wp-attached-media-audit-thumb"
			/>
		);
	}
	return (
		<span
			className="dashicons dashicons-media-default wp-attached-media-audit-thumb-icon"
			aria-hidden="true"
		/>
	);
}
