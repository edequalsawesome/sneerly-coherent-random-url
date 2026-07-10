/**
 * Sneerly Coherent Random Post Button Block
 */

// Import WordPress dependencies
import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { 
	useBlockProps, 
	RichText,
	BlockControls,
	InspectorControls,
	PanelColorSettings,
	ContrastChecker,
	__experimentalLinkControl as LinkControl,
} from '@wordpress/block-editor';
import { 
	ToolbarGroup, 
	ToolbarButton,
	PanelBody,
	ToggleControl,
	Button,
	SelectControl,
	RangeControl,
} from '@wordpress/components';
import { 
	link, 
	linkOff 
} from '@wordpress/icons';
import { useState } from '@wordpress/element';

// Import styles
import './editor.css';

// Register the block
registerBlockType('sneerly-coherent/random-post-button', {
	title: __('Random Post Button', 'sneerly-coherent-random'),
	description: __('Add a button that links to a random post.', 'sneerly-coherent-random'),
	category: 'widgets',
	icon: 'randomize',
	supports: {
		html: false,
		align: true,
	},
	attributes: {
		text: {
			type: 'string',
			default: __('Read a Random Post', 'sneerly-coherent-random'),
		},
		backgroundColor: {
			type: 'string',
		},
		textColor: {
			type: 'string',
		},
		borderRadius: {
			type: 'number',
			default: 4,
		},
		fontSize: {
			type: 'string',
			default: 'normal',
		},
		useRandomLink: {
			type: 'boolean',
			default: true,
		},
		customLink: {
			type: 'string',
			default: '',
		},
		linkOpensInNewTab: {
			type: 'boolean',
			default: false,
		},
		buttonWidth: {
			type: 'string',
			default: 'auto',
		},
	},
	
	// Define the edit interface
	edit: ({ attributes, setAttributes }) => {
		const {
			text,
			backgroundColor,
			textColor,
			borderRadius,
			fontSize,
			useRandomLink,
			customLink,
			linkOpensInNewTab,
			buttonWidth,
		} = attributes;
		
		// State for button link editing
		const [isEditingURL, setIsEditingURL] = useState(false);
		
		// Get block props with proper styling
		const blockProps = useBlockProps({
			className: 'sneer-campaign-random-button',
		});
		
		// Helper to get computed button style
		const getButtonStyle = () => {
			const style = {
				backgroundColor: backgroundColor || undefined,
				color: textColor || undefined,
				borderRadius: borderRadius ? `${borderRadius}px` : undefined,
				width: buttonWidth === 'full' ? '100%' : undefined,
			};
			
			return style;
		};
		
		// Font size class
		const fontSizeClass = fontSize ? `has-${fontSize}-font-size` : '';
		
		return (
			<div {...blockProps}>
				{/* Block Controls (top toolbar) */}
				<BlockControls>
					<ToolbarGroup>
						<ToolbarButton
							icon={!useRandomLink && !customLink ? link : linkOff}
							title={
								!isEditingURL
									? __('Edit Link', 'sneerly-coherent-random')
									: __('Close Link', 'sneerly-coherent-random')
							}
							onClick={() => setIsEditingURL(!isEditingURL)}
							isActive={isEditingURL}
						/>
					</ToolbarGroup>
				</BlockControls>
				
				{/* Inspector Controls (sidebar) */}
				<InspectorControls>
					<PanelBody title={__('Link Settings', 'sneerly-coherent-random')}>
						<ToggleControl
							label={__('Link to Random Post', 'sneerly-coherent-random')}
							checked={useRandomLink}
							onChange={(value) => setAttributes({ useRandomLink: value })}
							help={
								useRandomLink
									? __('Button links to a random post on your site.', 'sneerly-coherent-random')
									: __('Button uses a custom link.', 'sneerly-coherent-random')
							}
						/>
						
						{!useRandomLink && (
							<div className="wp-block-button__link-customization">
								<LinkControl
									searchInputPlaceholder={__('Type URL or paste...', 'sneerly-coherent-random')}
									value={{ url: customLink, opensInNewTab: linkOpensInNewTab }}
									onChange={({ url, opensInNewTab }) => 
										setAttributes({ 
											customLink: url,
											linkOpensInNewTab: !!opensInNewTab
										})
									}
								/>
							</div>
						)}
						
						{useRandomLink && (
							<ToggleControl
								label={__('Open in new tab', 'sneerly-coherent-random')}
								checked={linkOpensInNewTab}
								onChange={(value) => setAttributes({ linkOpensInNewTab: value })}
							/>
						)}
					</PanelBody>
					
					<PanelBody title={__('Button Settings', 'sneerly-coherent-random')}>
						<SelectControl
							label={__('Font Size', 'sneerly-coherent-random')}
							value={fontSize}
							options={[
								{ label: __('Small', 'sneerly-coherent-random'), value: 'small' },
								{ label: __('Normal', 'sneerly-coherent-random'), value: 'normal' },
								{ label: __('Medium', 'sneerly-coherent-random'), value: 'medium' },
								{ label: __('Large', 'sneerly-coherent-random'), value: 'large' },
							]}
							onChange={(value) => setAttributes({ fontSize: value })}
						/>
						
						<SelectControl
							label={__('Button Width', 'sneerly-coherent-random')}
							value={buttonWidth}
							options={[
								{ label: __('Auto', 'sneerly-coherent-random'), value: 'auto' },
								{ label: __('Full Width', 'sneerly-coherent-random'), value: 'full' },
							]}
							onChange={(value) => setAttributes({ buttonWidth: value })}
						/>
						
						<RangeControl
							label={__('Border Radius', 'sneerly-coherent-random')}
							value={borderRadius}
							onChange={(value) => setAttributes({ borderRadius: value })}
							min={0}
							max={50}
						/>
					</PanelBody>
					
					<PanelColorSettings
						title={__('Color Settings', 'sneerly-coherent-random')}
						colorSettings={[
							{
								value: backgroundColor,
								onChange: (value) => setAttributes({ backgroundColor: value }),
								label: __('Background Color', 'sneerly-coherent-random'),
							},
							{
								value: textColor,
								onChange: (value) => setAttributes({ textColor: value }),
								label: __('Text Color', 'sneerly-coherent-random'),
							},
						]}
					>
						<ContrastChecker
							backgroundColor={ backgroundColor }
							textColor={ textColor }
						/>
					</PanelColorSettings>
				</InspectorControls>
				
				{/* Button Preview */}
				<div className="wp-block-button">
					<RichText
						tagName="a"
						placeholder={__('Add text…', 'sneerly-coherent-random')}
						value={text}
						onChange={(value) => setAttributes({ text: value })}
						withoutInteractiveFormatting
						className={`wp-block-button__link ${fontSizeClass}`}
						style={getButtonStyle()}
					/>
				</div>
				
				{/* Link information display */}
				<div className="random-button-link-info">
					{useRandomLink ? (
						<p className="random-button-help-text">
							{__('This button will link to a random post when clicked.', 'sneerly-coherent-random')}
						</p>
					) : customLink ? (
						<p className="random-button-help-text">
							{__('Custom link: ', 'sneerly-coherent-random')}
							<code>{customLink}</code>
							{linkOpensInNewTab && ` (${__('opens in new tab', 'sneerly-coherent-random')})`}
						</p>
					) : (
						<p className="random-button-help-text">
							{__('No link set. Click the link button in the toolbar to add one.', 'sneerly-coherent-random')}
						</p>
					)}
				</div>
			</div>
		);
	},
	
	// Define the save output
	save: ({ attributes }) => {
		const {
			text,
			backgroundColor,
			textColor,
			borderRadius,
			fontSize,
			useRandomLink,
			customLink,
			linkOpensInNewTab,
			buttonWidth,
		} = attributes;
		
		// Create link URL - either random or custom.
		// Must be deterministic: save() output is re-validated against stored
		// markup on every editor load, so a Date.now() value here breaks block
		// validation. The PHP render_callback appends the cache-buster anyway.
		const href = useRandomLink ? '?random' : customLink;
		
		// Get button style
		const buttonStyle = {
			backgroundColor: backgroundColor || undefined,
			color: textColor || undefined,
			borderRadius: borderRadius ? `${borderRadius}px` : undefined,
			width: buttonWidth === 'full' ? '100%' : undefined,
		};
		
		// Font size class
		const fontSizeClass = fontSize ? `has-${fontSize}-font-size` : '';
		
		// Link target and rel attributes
		const target = linkOpensInNewTab ? '_blank' : undefined;
		const rel = linkOpensInNewTab ? 'noopener noreferrer' : undefined;
		
		return (
			<div className="wp-block-button">
				<a
					href={href}
					className={`wp-block-button__link ${fontSizeClass}`}
					style={buttonStyle}
					target={target}
					rel={rel}
				>
					<RichText.Content value={text} />
				</a>
			</div>
		);
	},
});