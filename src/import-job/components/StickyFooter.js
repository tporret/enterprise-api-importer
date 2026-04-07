import { Button, Flex, FlexItem } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function StickyFooter( {
	isEdit,
	saving,
	onSave,
	onRunImport,
	onTemplateSync,
} ) {
	return (
		<div className="eapi-ij-sticky-footer">
			<Flex justify="flex-start" gap={ 3 }>
				<FlexItem>
					<Button
						variant="primary"
						isBusy={ saving }
						disabled={ saving }
						onClick={ onSave }
					>
						{ saving
							? __( 'Saving…', 'enterprise-api-importer' )
							: isEdit
								? __( 'Update Import', 'enterprise-api-importer' )
								: __( 'Create Import', 'enterprise-api-importer' ) }
					</Button>
				</FlexItem>

				{ isEdit && (
					<>
						<FlexItem>
							<Button
								variant="secondary"
								disabled={ saving }
								onClick={ onRunImport }
							>
								{ __( 'Run Import Now', 'enterprise-api-importer' ) }
							</Button>
						</FlexItem>
						<FlexItem>
							<Button
								variant="secondary"
								disabled={ saving }
								onClick={ onTemplateSync }
							>
								{ __( 'Update Existing Items', 'enterprise-api-importer' ) }
							</Button>
						</FlexItem>
					</>
				) }
			</Flex>
		</div>
	);
}
