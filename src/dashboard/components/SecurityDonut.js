import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip } from 'recharts';
import { ShieldCheck, ShieldAlert } from 'lucide-react';

const COLORS = {
	green: '#10b981',
	red: '#ef4444',
	yellow: '#f59e0b',
	neutral: '#94a3b8',
};

export default function SecurityDonut( { items = {} } ) {
	const entries = Object.values( items );

	if ( ! entries.length ) {
		return (
			<p className="eapi-text-sm eapi-text-slate-400 eapi-m-0">
				No security data available.
			</p>
		);
	}

	// Build pie data from the security reporters.
	const segments = entries.map( ( reporter ) => {
		const s = reporter.metrics?.status || 'green';
		return {
			name: reporter.label,
			value: 1,
			color: COLORS[ s ] || COLORS.neutral,
			status: s,
			detail: reporter.metrics?.value || '',
		};
	} );

	const greenCount = segments.filter( ( s ) => s.status === 'green' ).length;
	const total = segments.length;
	const allSecure = greenCount === total;

	return (
		<div className="eapi-flex eapi-flex-col eapi-items-center">
			<div className="eapi-w-full eapi-h-48 eapi-relative">
				<ResponsiveContainer width="100%" height="100%">
					<PieChart>
						<Pie
							data={ segments }
							cx="50%"
							cy="50%"
							innerRadius={ 55 }
							outerRadius={ 80 }
							paddingAngle={ 3 }
							dataKey="value"
							stroke="none"
						>
							{ segments.map( ( seg, i ) => (
								<Cell
									key={ i }
									fill={ seg.color }
								/>
							) ) }
						</Pie>
						<Tooltip
							content={ ( { active, payload } ) => {
								if ( ! active || ! payload?.length )
									return null;
								const d = payload[ 0 ].payload;
								return (
									<div className="eapi-bg-white eapi-shadow-lg eapi-rounded-lg eapi-px-3 eapi-py-2 eapi-ring-1 eapi-ring-slate-200 eapi-text-xs">
										<p className="eapi-font-semibold eapi-text-slate-700 eapi-m-0">
											{ d.name }
										</p>
										<p className="eapi-text-slate-500 eapi-m-0">
											{ d.detail }
										</p>
									</div>
								);
							} }
						/>
					</PieChart>
				</ResponsiveContainer>

				{ /* Center label */ }
				<div className="eapi-absolute eapi-inset-0 eapi-flex eapi-flex-col eapi-items-center eapi-justify-center eapi-pointer-events-none">
					{ allSecure ? (
						<ShieldCheck
							size={ 24 }
							className="eapi-text-emerald-500"
						/>
					) : (
						<ShieldAlert
							size={ 24 }
							className="eapi-text-amber-500"
						/>
					) }
					<span className="eapi-text-xs eapi-font-semibold eapi-text-slate-600 eapi-mt-1">
						{ greenCount }/{ total }
					</span>
				</div>
			</div>

			{ /* Legend */ }
			<div className="eapi-w-full eapi-mt-2 eapi-space-y-1.5">
				{ segments.map( ( seg, i ) => (
					<div
						key={ i }
						className="eapi-flex eapi-items-center eapi-justify-between eapi-text-xs"
					>
						<div className="eapi-flex eapi-items-center eapi-gap-2">
							<span
								className="eapi-w-2.5 eapi-h-2.5 eapi-rounded-full eapi-flex-shrink-0"
								style={ { backgroundColor: seg.color } }
							/>
							<span className="eapi-text-slate-600 eapi-truncate">
								{ seg.name }
							</span>
						</div>
						<span className="eapi-font-medium eapi-text-slate-500">
							{ seg.detail }
						</span>
					</div>
				) ) }
			</div>
		</div>
	);
}
