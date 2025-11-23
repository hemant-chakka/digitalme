wp.blocks.registerBlockType( 'sacd/ac-form-block', {
    title: 'ActiveCampaign Form',
    icon: 'feedback',
    category: 'embed',
    attributes: {
        formId: {
            type: 'string',
            default: null,
        }
    },
    edit: function ( props ) {
        const { useState, useEffect, createElement } = wp.element;
        const { attributes, setAttributes } = props;
        const [ options, setOptions ] = useState( [] );
        useEffect( function () {
            fetch( sacd.ajaxUrl + '?action=sacd_get_ac_forms&nonce=' + sacd.nonce )
            .then( function ( response ) {
                return response.json();
            } )
            .then( function ( data ) {
                const options = data.data.forms.map( function ( form ) {
                    return {
                        value: form.id,
                        label: form.name,
                    };
                });                
                setOptions( options );
            }); 
        }, []);
        return createElement(
            'select',
            {
                value: attributes.formId,
                onChange: function ( e ) {
                    setAttributes({ formId: e.target.value });
                },
                style: { width: '100%', padding: '8px' },
            },
            [
                createElement(
                    'option',
                    { value: '', key: 'default' },
                    'Select form'
                ),
                ...options.map( function (option) {
                    return createElement(
                        'option',
                        { value: option.value, key: option.value },
                        option.label,
                    )
                } )
            ],
        );
    },
    save: function ( props ) {
        const formId = props.attributes.formId;
        return wp.element.createElement(
            wp.element.Fragment,
            null,
            wp.element.createElement( 'div', { id: 'ac-form', className: '_form_' + formId } ),
            wp.element.createElement( 'script', {
                src: sacd.apiUrl + '/f/embed.php?id=' + formId,
                charset: 'utf-8',
            } )
        );        
    }
});

( function( wp ) {
	const registerPlugin = wp.plugins.registerPlugin;
	const PluginSidebar = wp.editor.PluginSidebar;
	const el = wp.element.createElement;
	const { useState, useEffect } = wp.element;
	const PanelBody = wp.components.PanelBody;
	const SelectControl = wp.components.SelectControl;
	const withSelect = wp.data.withSelect;
	const withDispatch = wp.data.withDispatch;
	const compose = wp.compose.compose;

	const AllowedTag = compose(
		withSelect( ( select ) => {
			const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
			let tagsId;
            try {
                tagsId = JSON.parse( meta.sacd_tag_id );
            } catch ( e ) {
                tagsId = [];
            }
			return {
				metaValue: tagsId
			};
		}),
		withDispatch( ( dispatch ) => {
			return {
				setMetaValue: ( value ) => {
					dispatch( 'core/editor' ).editPost( {
                        meta: {
                            sacd_tag_id: value
                        }
                    } );
				}
			};
		})
	)( function( props ) {
		const [ options, setOptions ] = useState([
			{ label: 'Loading...', value: '' }
		]);

		useEffect( function() {
			fetch( sacd.ajaxUrl + '?action=sacd_get_ac_tags&nonce=' + sacd.nonce )
				.then( res => res.json() )
				.then( data => {
					const opts = data.data.tags.map( tag => ( {
						label: tag.tag,
						value: tag.id
					} ) );
					setOptions( [ { label: 'None', value: '' }, ...opts ] );
				});
		}, []);

        let valueId = ''
        if ( props.metaValue.length == 0 ) {
            valueId = '';
        } else {
            valueId = props.metaValue[0];
        }

		return el( SelectControl, {
			label: 'Choose Tag',
            options: options,
			value: valueId,
			onChange: ( val ) => props.setMetaValue( JSON.stringify( [ val ] ) ),
            __next40pxDefaultSize: true,
            __nextHasNoMarginBottom: true
		} );
	});

    const DisallowedTag = compose(
		withSelect( ( select ) => {
			const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
            let tagsId;
            try {
                tagsId = JSON.parse( meta.sacd_disallowed_tag_id );
            } catch ( e ) {
                tagsId = [];
            }
			return {
				metaValue: tagsId,
                fallbackUrl: meta.sacd_fallback_url || ''
			};
		}),
		withDispatch( ( dispatch ) => {
			return {
				setMetaValue: ( value ) => {
					dispatch( 'core/editor' ).editPost( {
                        meta: {
                            sacd_disallowed_tag_id: value
                        }
                    } );
				},
                setFallbackUrl: ( value ) => {
                    dispatch( 'core/editor' ).editPost( {
                        meta: {
                            sacd_fallback_url: value
                        }
                    } );
                }
			};
		})
	)( function( props ) {
		const [ options, setOptions ] = useState( [
			{ label: 'Loading...', value: '' }
		] );

		useEffect( function() {
			fetch( sacd.ajaxUrl + '?action=sacd_get_ac_tags&nonce=' + sacd.nonce )
				.then( res => res.json() )
				.then( data => {
					const opts = data.data.tags.map( tag => ( {
						label: tag.tag,
						value: tag.id
					} ) );
					setOptions( [ { label: 'None', value: '' }, ...opts ] );
				} );
		}, [] );

        let valueId = ''
        if ( props.metaValue.length == 0 ) {
            valueId = '';
        } else {
            valueId = props.metaValue[0];
        }

		return el( 'div', {},
            el( SelectControl, {
                label: 'Choose Tag',
                options: options,
                value: valueId,
                onChange: ( val ) => props.setMetaValue( JSON.stringify( [ val ] ) ),
                __next40pxDefaultSize: true
            }),
            el( wp.components.TextControl, {
                label: 'Fallback URL',
                value: props.fallbackUrl,
                onChange: ( val ) => props.setFallbackUrl(val),
                __next40pxDefaultSize: true,
                __nextHasNoMarginBottom: true
            })
        );
	});

	registerPlugin( 'sacd-sidebar', {
		render: function() {
			return el(
				PluginSidebar,
				{ name: 'sacd-sidebar', title: 'Simple ActiveCampaign Membership' },
				el( PanelBody, { title: 'Allowed Tag' }, el( AllowedTag ) ),
                el( PanelBody, { title: 'Disallowed Tag' }, el( DisallowedTag ) )
			);
		},
		icon: 'admin-generic'
	} );
} )( window.wp );

