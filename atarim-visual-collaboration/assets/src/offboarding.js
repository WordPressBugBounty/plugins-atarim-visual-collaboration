import { useState, useEffect, useRef } from '@wordpress/element';
import { createRoot } from 'react-dom/client';

const cfg = window.avcOffboarding || {};
const i18n = cfg.i18n || {};

/**
 * Return the clicked action when it is this plugin's "Deactivate" link.
 * Returns null otherwise.
 */
function classifyClick( event ) {
	const anchor = event.target.closest( 'a' );
	if ( ! anchor ) {
		return null;
	}

	const row = anchor.closest( 'tr[data-plugin]' );
	if ( ! row || row.getAttribute( 'data-plugin' ) !== cfg.pluginBasename ) {
		return null;
	}

	const href = anchor.getAttribute( 'href' ) || '';

	if ( href.indexOf( 'action=deactivate' ) !== -1 || anchor.id.indexOf( 'deactivate-' ) === 0 ) {
		return { type: 'deactivate', href };
	}

	return null;
}

const DeactivateModal = ( { onClose } ) => {
	const reasons = cfg.reasons || [];
	const [ reason, setReason ] = useState( '' );
	const [ comment, setComment ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const isOther = reason === cfg.otherValue;

	const goDeactivate = ( deactivateHref ) => {
		window.location.assign( deactivateHref );
	};

	const handleSubmit = () => {
		if ( ! reason ) {
			return; // submit stays disabled until a reason is picked
		}
		if ( isOther && comment.trim() === '' ) {
			setError( i18n.otherRequired );
			return;
		}

		const body = new URLSearchParams();
		body.append( 'action', 'avcf_deactivation_feedback' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'reason', reason );
		body.append( 'comment', comment );

		// Fire and forget — we do not wait for the API before deactivating.
		try {
			fetch( cfg.ajaxurl, {
				method: 'POST',
				headers: { 'X-AVC-Nonce': cfg.nonce },
				body,
				keepalive: true,
				credentials: 'same-origin',
			} ).catch( () => {} );
		} catch ( e ) {
			// ignore — deactivation must proceed regardless
		}

		goDeactivate( window.__avcDeactivateHref );
	};

	const handleSkip = () => {
		goDeactivate( window.__avcDeactivateHref );
	};

	return (
		<div className="avc-off-overlay" role="dialog" aria-modal="true" aria-label={ i18n.deactivateTitle }>
			<div className="avc-off-modal">
				<div className="avc-off-head">
					{ cfg.logoUrl ? <img className="avc-off-logo" src={ cfg.logoUrl } alt="Atarim" /> : null }
					<span className="avc-off-title">{ i18n.deactivateTitle }</span>
				</div>
				<div className="avc-off-body">
					<p className="avc-off-prompt">{ i18n.deactivatePrompt }</p>
					<ul className="avc-off-options">
						{ reasons.map( ( r ) => (
							<li key={ r.value }>
								<label className="avc-off-option">
									<input
										type="radio"
										name="avc-off-reason"
										value={ r.value }
										checked={ reason === r.value }
										onChange={ () => {
											setReason( r.value );
											setError( '' );
										} }
									/>
									<span>{ r.label }</span>
								</label>
								{ r.value === cfg.otherValue && isOther ? (
									<textarea
										className="avc-off-textarea"
										rows="3"
										value={ comment }
										placeholder={ i18n.otherPlaceholder }
										onChange={ ( e ) => {
											setComment( e.target.value );
											if ( e.target.value.trim() !== '' ) {
												setError( '' );
											}
										} }
									/>
								) : null }
							</li>
						) ) }
					</ul>
					{ error ? <p className="avc-off-error">{ error }</p> : null }
				</div>
				<div className="avc-off-foot">
					<button
						type="button"
						className="avc-off-btn avc-off-btn-primary"
						disabled={ ! reason }
						onClick={ handleSubmit }
					>
						{ i18n.submitDeactivate }
					</button>
					<button type="button" className="avc-off-btn avc-off-btn-link" onClick={ handleSkip }>
						{ i18n.skipDeactivate }
					</button>
				</div>
				<button type="button" className="avc-off-close" aria-label={ i18n.cancel } onClick={ onClose }>
					&times;
				</button>
			</div>
		</div>
	);
};

const Offboarding = () => {
	const [ active, setActive ] = useState( null ); // null | 'deactivate'
	const activeRef = useRef( active );
	activeRef.current = active;

	useEffect( () => {
		const onClick = ( event ) => {
			const hit = classifyClick( event );
			if ( ! hit ) {
				return;
			}
			// Beat WordPress's own deactivate navigation.
			event.preventDefault();
			event.stopImmediatePropagation();

			window.__avcDeactivateHref = hit.href;
			setActive( 'deactivate' );
		};

		const onKey = ( event ) => {
			if ( event.key === 'Escape' && activeRef.current ) {
				setActive( null );
			}
		};

		// Capture phase so we run before core's bubble-phase listeners.
		document.addEventListener( 'click', onClick, true );
		document.addEventListener( 'keydown', onKey );
		return () => {
			document.removeEventListener( 'click', onClick, true );
			document.removeEventListener( 'keydown', onKey );
		};
	}, [] );

	const close = () => setActive( null );

	if ( active === 'deactivate' ) {
		return <DeactivateModal onClose={ close } />;
	}
	return null;
};

const mount = document.createElement( 'div' );
mount.id = 'avc-offboarding-root';
document.body.appendChild( mount );
createRoot( mount ).render( <Offboarding /> );
