import { createRoot } from '@wordpress/element';
import Dashboard from './Dashboard';
import './style.css';

const rootEl = document.getElementById( 'eapi-dashboard-root' );
if ( rootEl ) {
	const root = createRoot( rootEl );
	root.render( <Dashboard /> );
}
