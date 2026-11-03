import { __ } from '@wordpress/i18n';
import { SettingsPage, type SettingsFieldConfig } from '@prc/components';

import './style.scss';
import { fetchSettings, saveSettings } from './api';
import { store as settingsStore } from './store';
import MemoriesSection from './components/memories-section';

const HARVEST_FIELDS: SettingsFieldConfig[] = [
	{
		id: 'enabled',
		type: 'boolean',
		label: __(
			'Harvest notes on publish',
			'prc-nexus-self-improving-guidelines'
		),
		description: __(
			'When on, the plugin reads Gutenberg editor notes after a post publishes or a published post updates. Proposed memories wait here for review. Guidelines never update until a person injects a memory.',
			'prc-nexus-self-improving-guidelines'
		),
	},
];

export default function SettingsApp() {
	return (
		<SettingsPage
			title={__(
				'Editorial Memories',
				'prc-nexus-self-improving-guidelines'
			)}
			description={__(
				'Turn Gutenberg editor notes into candidate editorial rules, then inject the ones you want into Settings → Guidelines.',
				'prc-nexus-self-improving-guidelines'
			)}
			textDomain="prc-nexus-self-improving-guidelines"
			idPrefix="prc-nexus-self-improving-guidelines-settings"
			store={settingsStore}
			saveSettings={saveSettings}
			sections={[
				{
					slug: 'harvest',
					title: __('Harvest', 'prc-nexus-self-improving-guidelines'),
					description: __(
						'Harvest follows the post-publish pipeline post types. Public comments are ignored.',
						'prc-nexus-self-improving-guidelines'
					),
					fields: HARVEST_FIELDS,
				},
				{
					slug: 'memories',
					title: __(
						'Memories',
						'prc-nexus-self-improving-guidelines'
					),
					description: __(
						'Review proposed rules. Inject appends the rule to the matching Guidelines scope. Reject drops it.',
						'prc-nexus-self-improving-guidelines'
					),
					render: () => <MemoriesSection />,
				},
			]}
			onLoad={fetchSettings}
		/>
	);
}
