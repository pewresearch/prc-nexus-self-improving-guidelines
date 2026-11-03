import { createSettingsStore } from '@prc/components';
import type { Settings, SettingsStoreState, ApiResponse } from './types';

export const STORE_NAME = 'prc/nexus-self-improving-guidelines-settings';

export const store = createSettingsStore<
	Settings,
	SettingsStoreState,
	ApiResponse
>({
	name: STORE_NAME,
	defaultState: {
		settings: {
			enabled: true,
		},
		isLoaded: false,
	},
});
