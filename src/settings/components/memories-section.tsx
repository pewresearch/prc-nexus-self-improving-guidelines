import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import {
	Button,
	CheckboxControl,
	SelectControl,
	Spinner,
	TextareaControl,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { store as noticesStore } from '@wordpress/notices';
import { SettingsSubSection } from '@prc/components';

import {
	fetchMemories,
	injectMemory,
	injectMemories,
	rejectMemory,
	updateMemory,
} from '../api';
import type { EditorialMemory, MemoryScope } from '../types';
import { MEMORY_SCOPES } from '../types';

function assertNever(value: never): never {
	throw new Error(`Unhandled memory scope: ${String(value)}`);
}

function scopeLabel(scope: MemoryScope): string {
	switch (scope) {
		case 'site':
			return __('Site', 'prc-nexus-self-improving-guidelines');
		case 'copy':
			return __('Copy', 'prc-nexus-self-improving-guidelines');
		case 'images':
			return __('Images', 'prc-nexus-self-improving-guidelines');
		case 'additional':
			return __('Additional', 'prc-nexus-self-improving-guidelines');
		case 'blocks':
			return __('Blocks', 'prc-nexus-self-improving-guidelines');
		default:
			return assertNever(scope);
	}
}

type MemoryRowProps = {
	draft: EditorialMemory;
	selected: boolean;
	isBusy: boolean;
	onSelect: (checked: boolean) => void;
	onChange: (patch: Partial<EditorialMemory>) => void;
	onInject: () => void;
	onReject: () => void;
};

function MemoryRow({
	draft,
	selected,
	isBusy,
	onSelect,
	onChange,
	onInject,
	onReject,
}: MemoryRowProps) {
	return (
		<div className="nexus-self-improving-guidelines-settings__memory">
			<VStack spacing={3}>
				<CheckboxControl
					label={draft.title}
					checked={selected}
					onChange={onSelect}
					disabled={isBusy}
					__nextHasNoMarginBottom
				/>
				<TextareaControl
					label={__(
						'Proposed rule',
						'prc-nexus-self-improving-guidelines'
					)}
					value={draft.rule_text}
					onChange={(value) => onChange({ rule_text: value })}
					rows={4}
					disabled={isBusy}
					__nextHasNoMarginBottom
				/>
				<SelectControl
					label={__(
						'Guideline scope',
						'prc-nexus-self-improving-guidelines'
					)}
					value={draft.scope}
					options={MEMORY_SCOPES.map((option) => ({
						label: option.label,
						value: option.value,
					}))}
					onChange={(value) =>
						onChange({ scope: value as MemoryScope })
					}
					disabled={isBusy}
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{draft.scope === 'blocks' ? (
					<TextControl
						label={__(
							'Block name',
							'prc-nexus-self-improving-guidelines'
						)}
						value={draft.block_name}
						onChange={(value) => onChange({ block_name: value })}
						help={__(
							'Example: core/paragraph',
							'prc-nexus-self-improving-guidelines'
						)}
						disabled={isBusy}
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				) : null}
				{draft.rationale ? (
					<Text size={12}>{draft.rationale}</Text>
				) : null}
				{draft.source_post_url ? (
					<Text size={12}>
						<a href={draft.source_post_url}>
							{draft.source_post_title ||
								__(
									'Source post',
									'prc-nexus-self-improving-guidelines'
								)}
						</a>
					</Text>
				) : null}
				<HStack alignment="left" spacing={2}>
					<Button
						variant="primary"
						onClick={onInject}
						disabled={isBusy}
						isBusy={isBusy}
						__next40pxDefaultSize
					>
						{__('Inject', 'prc-nexus-self-improving-guidelines')}
					</Button>
					<Button
						variant="secondary"
						onClick={onReject}
						disabled={isBusy}
						__next40pxDefaultSize
					>
						{__('Reject', 'prc-nexus-self-improving-guidelines')}
					</Button>
				</HStack>
			</VStack>
		</div>
	);
}

type InjectedHistoryProps = {
	injected: EditorialMemory[];
	isOpen: boolean;
	onToggle: () => void;
};

function InjectedHistory({ injected, isOpen, onToggle }: InjectedHistoryProps) {
	return (
		<SettingsSubSection
			title={__(
				'Injected history',
				'prc-nexus-self-improving-guidelines'
			)}
			textDomain="prc-nexus-self-improving-guidelines"
			isOpen={isOpen}
			onToggle={onToggle}
			contentId="prc-nexus-sig-injected-history"
		>
			{injected.length === 0 ? (
				<Text>
					{__(
						'No memories have been injected yet.',
						'prc-nexus-self-improving-guidelines'
					)}
				</Text>
			) : (
				<VStack spacing={3}>
					{injected.map((row) => (
						<div key={row.id}>
							<p className="nexus-self-improving-guidelines-settings__history-title">
								{row.title}
							</p>
							<Text size={12}>
								{scopeLabel(row.scope)}
								{row.block_name ? ` · ${row.block_name}` : ''}
							</Text>
							<Text size={13}>{row.rule_text}</Text>
						</div>
					))}
				</VStack>
			)}
		</SettingsSubSection>
	);
}

export default function MemoriesSection() {
	const [pending, setPending] = useState<EditorialMemory[]>([]);
	const [injected, setInjected] = useState<EditorialMemory[]>([]);
	const [selected, setSelected] = useState<number[]>([]);
	const [drafts, setDrafts] = useState<Record<number, EditorialMemory>>({});
	const [isLoading, setIsLoading] = useState(true);
	const [busyId, setBusyId] = useState<number | 'bulk' | null>(null);
	const [historyOpen, setHistoryOpen] = useState(false);
	const { createSuccessNotice, createErrorNotice } =
		useDispatch(noticesStore);

	const load = useCallback(async () => {
		setIsLoading(true);
		try {
			const [pendingRows, injectedRows] = await Promise.all([
				fetchMemories('pending'),
				fetchMemories('injected'),
			]);
			setPending(pendingRows);
			setInjected(injectedRows);
			setDrafts(
				Object.fromEntries(pendingRows.map((row) => [row.id, row]))
			);
			setSelected([]);
		} catch {
			createErrorNotice(
				__(
					'Could not load editorial memories.',
					'prc-nexus-self-improving-guidelines'
				),
				{ type: 'snackbar' }
			);
		} finally {
			setIsLoading(false);
		}
	}, [createErrorNotice]);

	useEffect(() => {
		void load();
	}, [load]);

	function updateDraft(id: number, patch: Partial<EditorialMemory>): void {
		setDrafts((current) => {
			const existing = current[id];
			if (!existing) {
				return current;
			}
			return {
				...current,
				[id]: { ...existing, ...patch },
			};
		});
	}

	async function persistDraft(id: number): Promise<EditorialMemory | null> {
		const draft = drafts[id];
		if (!draft) {
			return null;
		}

		return updateMemory(id, {
			title: draft.title,
			rule_text: draft.rule_text,
			scope: draft.scope,
			block_name: draft.block_name,
			rationale: draft.rationale,
		});
	}

	async function handleInject(id: number): Promise<void> {
		setBusyId(id);
		try {
			await persistDraft(id);
			await injectMemory(id);
			createSuccessNotice(
				__(
					'Memory injected into Guidelines.',
					'prc-nexus-self-improving-guidelines'
				),
				{ type: 'snackbar' }
			);
			await load();
		} catch (error) {
			const message =
				error instanceof Error
					? error.message
					: __(
							'Could not inject this memory.',
							'prc-nexus-self-improving-guidelines'
						);
			createErrorNotice(message, { type: 'snackbar' });
		} finally {
			setBusyId(null);
		}
	}

	async function handleReject(id: number): Promise<void> {
		setBusyId(id);
		try {
			await rejectMemory(id);
			createSuccessNotice(
				__('Memory rejected.', 'prc-nexus-self-improving-guidelines'),
				{ type: 'snackbar' }
			);
			await load();
		} catch {
			createErrorNotice(
				__(
					'Could not reject this memory.',
					'prc-nexus-self-improving-guidelines'
				),
				{ type: 'snackbar' }
			);
		} finally {
			setBusyId(null);
		}
	}

	async function handleBulkInject(): Promise<void> {
		if (selected.length === 0) {
			return;
		}
		setBusyId('bulk');
		try {
			for (const id of selected) {
				await persistDraft(id);
			}
			const result = await injectMemories(selected);
			if (result.errors.length > 0) {
				createErrorNotice(
					__(
						'Some memories could not be injected. Check the remaining list.',
						'prc-nexus-self-improving-guidelines'
					),
					{ type: 'snackbar' }
				);
			} else {
				createSuccessNotice(
					__(
						'Selected memories injected into Guidelines.',
						'prc-nexus-self-improving-guidelines'
					),
					{ type: 'snackbar' }
				);
			}
			await load();
		} catch {
			createErrorNotice(
				__(
					'Could not inject the selected memories.',
					'prc-nexus-self-improving-guidelines'
				),
				{ type: 'snackbar' }
			);
		} finally {
			setBusyId(null);
		}
	}

	if (isLoading) {
		return <Spinner />;
	}

	return (
		<VStack spacing={4}>
			{pending.length === 0 ? (
				<Text>
					{__(
						'No pending memories. Publish a post that has Gutenberg notes to harvest candidates.',
						'prc-nexus-self-improving-guidelines'
					)}
				</Text>
			) : (
				<>
					<HStack alignment="left" spacing={2}>
						<Button
							variant="primary"
							onClick={() => {
								void handleBulkInject();
							}}
							disabled={selected.length === 0 || busyId !== null}
							isBusy={busyId === 'bulk'}
							__next40pxDefaultSize
						>
							{__(
								'Inject selected',
								'prc-nexus-self-improving-guidelines'
							)}
						</Button>
					</HStack>
					{pending.map((row) => {
						const draft = drafts[row.id] ?? row;
						const isBusy = busyId === row.id || busyId === 'bulk';
						return (
							<MemoryRow
								key={row.id}
								draft={draft}
								selected={selected.includes(row.id)}
								isBusy={isBusy}
								onSelect={(checked) => {
									setSelected((current) =>
										checked
											? [...current, row.id]
											: current.filter(
													(value) => value !== row.id
												)
									);
								}}
								onChange={(patch) => updateDraft(row.id, patch)}
								onInject={() => {
									void handleInject(row.id);
								}}
								onReject={() => {
									void handleReject(row.id);
								}}
							/>
						);
					})}
				</>
			)}
			<InjectedHistory
				injected={injected}
				isOpen={historyOpen}
				onToggle={() => setHistoryOpen((open) => !open)}
			/>
		</VStack>
	);
}
