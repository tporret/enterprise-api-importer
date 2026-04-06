import { Activity, Layers, Timer, Network } from 'lucide-react';
import {
	AreaChart,
	Area,
	ResponsiveContainer,
} from 'recharts';

const ICONS = {
	activity: Activity,
	layers: Layers,
	timer: Timer,
	network: Network,
};

const COLOR_MAP = {
	green: {
		text: 'eapi-text-emerald-600',
		bg: 'eapi-bg-emerald-50',
		ring: 'eapi-ring-emerald-200',
		icon: 'eapi-text-emerald-500',
		spark: '#10b981',
	},
	yellow: {
		text: 'eapi-text-amber-600',
		bg: 'eapi-bg-amber-50',
		ring: 'eapi-ring-amber-200',
		icon: 'eapi-text-amber-500',
		spark: '#f59e0b',
	},
	red: {
		text: 'eapi-text-rose-600',
		bg: 'eapi-bg-rose-50',
		ring: 'eapi-ring-rose-200',
		icon: 'eapi-text-rose-500',
		spark: '#ef4444',
	},
};

export default function StatCard( {
	label,
	value,
	icon,
	status = 'green',
	sparkData = [],
} ) {
	const IconComponent = ICONS[ icon ] || Activity;
	const colors = COLOR_MAP[ status ] || COLOR_MAP.green;

	const chartData = sparkData
		.filter( ( v ) => v !== null && v !== undefined )
		.map( ( v, i ) => ( { idx: i, val: v } ) );

	return (
		<div className="eapi-bg-white eapi-rounded-xl eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 eapi-p-5 eapi-transition-all eapi-duration-300 hover:eapi-shadow-md hover:eapi-ring-slate-300 eapi-flex eapi-flex-col eapi-justify-between eapi-min-h-[130px]">
			<div className="eapi-flex eapi-items-start eapi-justify-between">
				<div>
					<p className="eapi-text-sm eapi-font-medium eapi-text-slate-500 eapi-m-0 eapi-mb-1">
						{ label }
					</p>
					<p
						className={ `eapi-text-2xl eapi-font-bold eapi-m-0 ${ colors.text }` }
					>
						{ value }
					</p>
				</div>
				<div
					className={ `eapi-p-2 eapi-rounded-lg ${ colors.bg } eapi-ring-1 ${ colors.ring }` }
				>
					<IconComponent
						size={ 20 }
						className={ colors.icon }
					/>
				</div>
			</div>

			{ chartData.length > 1 && (
				<div className="eapi-mt-3 eapi-h-10">
					<ResponsiveContainer width="100%" height="100%">
						<AreaChart data={ chartData }>
							<defs>
								<linearGradient
									id={ `spark-${ label }` }
									x1="0"
									y1="0"
									x2="0"
									y2="1"
								>
									<stop
										offset="0%"
										stopColor={ colors.spark }
										stopOpacity={ 0.3 }
									/>
									<stop
										offset="100%"
										stopColor={ colors.spark }
										stopOpacity={ 0 }
									/>
								</linearGradient>
							</defs>
							<Area
								type="monotone"
								dataKey="val"
								stroke={ colors.spark }
								strokeWidth={ 2 }
								fill={ `url(#spark-${ label })` }
								dot={ false }
								isAnimationActive={ false }
							/>
						</AreaChart>
					</ResponsiveContainer>
				</div>
			) }
		</div>
	);
}
