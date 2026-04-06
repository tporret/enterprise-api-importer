import {
	CheckCircle2,
	AlertTriangle,
	XCircle,
} from 'lucide-react';

const STATUS_CONFIG = {
	green: {
		Icon: CheckCircle2,
		iconClass: 'eapi-text-emerald-500',
		barClass: 'eapi-bg-emerald-500',
	},
	yellow: {
		Icon: AlertTriangle,
		iconClass: 'eapi-text-amber-500',
		barClass: 'eapi-bg-amber-500',
	},
	red: {
		Icon: XCircle,
		iconClass: 'eapi-text-rose-500',
		barClass: 'eapi-bg-rose-500',
	},
};

export default function StatusList( { items = {} } ) {
	const entries = Object.entries( items );

	if ( ! entries.length ) {
		return (
			<p className="eapi-text-sm eapi-text-slate-400 eapi-m-0">
				No health data available.
			</p>
		);
	}

	return (
		<div className="eapi-space-y-3">
			{ entries.map( ( [ id, reporter ] ) => {
				const { metrics = {} } = reporter;
				const cfg =
					STATUS_CONFIG[ metrics.status ] || STATUS_CONFIG.green;
				const { Icon } = cfg;

				return (
					<div
						key={ id }
						className="eapi-flex eapi-items-start eapi-gap-3 eapi-group"
					>
						<Icon
							size={ 18 }
							className={ `eapi-mt-0.5 eapi-flex-shrink-0 ${ cfg.iconClass }` }
						/>
						<div className="eapi-flex-1 eapi-min-w-0">
							<div className="eapi-flex eapi-items-center eapi-justify-between eapi-mb-1">
								<span className="eapi-text-sm eapi-font-medium eapi-text-slate-700 eapi-truncate">
									{ reporter.label }
								</span>
								<span
									className={ `eapi-text-xs eapi-font-semibold ${ cfg.iconClass }` }
								>
									{ metrics.value }
								</span>
							</div>

							{ /* Progress bar for queue depth */ }
							{ id === 'queue_depth' && (
								<div className="eapi-w-full eapi-h-1.5 eapi-bg-slate-100 eapi-rounded-full eapi-overflow-hidden">
									<div
										className={ `eapi-h-full eapi-rounded-full eapi-transition-all eapi-duration-500 ${ cfg.barClass }` }
										style={ {
											width: `${ Math.min(
												parseFloat(
													metrics.value?.replace(
														/,/g,
														''
													)
												) || 0,
												100
											) }%`,
										} }
									/>
								</div>
							) }

							<p className="eapi-text-xs eapi-text-slate-400 eapi-m-0 eapi-mt-0.5 eapi-truncate">
								{ metrics.detail }
							</p>
						</div>
					</div>
				);
			} ) }
		</div>
	);
}
