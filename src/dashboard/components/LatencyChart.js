import {
	AreaChart,
	Area,
	XAxis,
	YAxis,
	CartesianGrid,
	Tooltip,
	ResponsiveContainer,
} from 'recharts';

export default function LatencyChart( { dataPoints = [] } ) {
	if ( ! dataPoints.length ) {
		return (
			<div className="eapi-flex eapi-items-center eapi-justify-center eapi-h-48">
				<p className="eapi-text-sm eapi-text-slate-400 eapi-m-0">
					No latency data available yet.
				</p>
			</div>
		);
	}

	return (
		<div className="eapi-h-56">
			<ResponsiveContainer width="100%" height="100%">
				<AreaChart
					data={ dataPoints }
					margin={ { top: 5, right: 5, left: -20, bottom: 0 } }
				>
					<defs>
						<linearGradient
							id="latencyGrad"
							x1="0"
							y1="0"
							x2="0"
							y2="1"
						>
							<stop
								offset="0%"
								stopColor="#6366f1"
								stopOpacity={ 0.25 }
							/>
							<stop
								offset="100%"
								stopColor="#6366f1"
								stopOpacity={ 0 }
							/>
						</linearGradient>
					</defs>
					<CartesianGrid
						strokeDasharray="3 3"
						stroke="#f1f5f9"
						vertical={ false }
					/>
					<XAxis
						dataKey="time"
						tick={ { fontSize: 10, fill: '#94a3b8' } }
						axisLine={ false }
						tickLine={ false }
					/>
					<YAxis
						tick={ { fontSize: 10, fill: '#94a3b8' } }
						axisLine={ false }
						tickLine={ false }
						unit="s"
					/>
					<Tooltip
						contentStyle={ {
							backgroundColor: '#fff',
							borderRadius: '8px',
							border: '1px solid #e2e8f0',
							fontSize: '12px',
							boxShadow:
								'0 4px 6px -1px rgb(0 0 0 / 0.1)',
						} }
						formatter={ ( v ) => [ `${ v }s`, 'Duration' ] }
					/>
					<Area
						type="monotone"
						dataKey="seconds"
						stroke="#6366f1"
						strokeWidth={ 2 }
						fill="url(#latencyGrad)"
						dot={ { r: 3, fill: '#6366f1', strokeWidth: 0 } }
						activeDot={ {
							r: 5,
							fill: '#6366f1',
							stroke: '#fff',
							strokeWidth: 2,
						} }
					/>
				</AreaChart>
			</ResponsiveContainer>
		</div>
	);
}
