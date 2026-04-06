import { FileText } from 'lucide-react';

export default function AuditMarquee( { entries = [] } ) {
	if ( ! entries.length ) {
		return (
			<div className="eapi-bg-white eapi-rounded-xl eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 eapi-px-5 eapi-py-3 eapi-flex eapi-items-center eapi-gap-3">
				<FileText
					size={ 14 }
					className="eapi-text-slate-400 eapi-flex-shrink-0"
				/>
				<span className="eapi-text-xs eapi-text-slate-400">
					No recent audit activity.
				</span>
			</div>
		);
	}

	const labels = {
		template_config_created: 'Created',
		template_config_updated: 'Updated',
	};

	// Duplicate entries to create a seamless loop.
	const doubled = [ ...entries, ...entries ];

	return (
		<div className="eapi-bg-white eapi-rounded-xl eapi-shadow-sm eapi-ring-1 eapi-ring-slate-200 eapi-overflow-hidden">
			<div className="eapi-flex eapi-items-center eapi-gap-3 eapi-px-5 eapi-py-3">
				<div className="eapi-flex eapi-items-center eapi-gap-2 eapi-flex-shrink-0">
					<FileText
						size={ 14 }
						className="eapi-text-indigo-500"
					/>
					<span className="eapi-text-xs eapi-font-semibold eapi-text-slate-500 eapi-uppercase eapi-tracking-wider">
						System Pulse
					</span>
				</div>

				<div className="eapi-flex-1 eapi-overflow-hidden eapi-relative">
					<div className="eapi-marquee-track">
						{ doubled.map( ( entry, i ) => (
							<span
								key={ i }
								className="eapi-inline-flex eapi-items-center eapi-gap-1.5 eapi-text-xs eapi-text-slate-600 eapi-whitespace-nowrap eapi-mr-8"
							>
								<span className="eapi-font-medium eapi-text-indigo-600">
									{ entry.user }
								</span>
								<span className="eapi-text-slate-400">
									{ labels[ entry.type ] || entry.type }
								</span>
								<span>import #{ entry.import }</span>
								<span className="eapi-text-slate-300">
									·
								</span>
								<span className="eapi-text-slate-400">
									{ entry.time }
								</span>
							</span>
						) ) }
					</div>
				</div>
			</div>
		</div>
	);
}
