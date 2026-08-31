import React from 'react';
import {act, fireEvent, render, screen, waitFor} from '@testing-library/react';
import App, {SettingsErrorBoundary} from './App';
import {
    apiDeleteChat,
    fetchBots,
    fetchBotsForChannels,
    fetchBotsForChats,
    fetchChannels,
    fetchChats,
    fetchChatsForChannels,
    fetchClient,
    fetchForms,
    fetchFormsForChannels,
} from './utils/api';

jest.mock('./components/Channel', () => ({channel, dataAvailability}) => (
    <div>
        Channel {channel.id}
        <span data-testid={`channel-${channel.id}-forms-state`}>{dataAvailability.forms}</span>
    </div>
));
jest.mock('./components/Bot', () => ({bot, chatDataStatus}) => (
    <div>
        Bot {bot.id}
        <span data-testid={`bot-${bot.id}-chats-state`}>{chatDataStatus}</span>
    </div>
));
jest.mock('./components/NewBot', () => () => <div>NewBot</div>);
jest.mock('./components/NewChannel', () => () => <div>NewChannel</div>);

jest.mock('./utils/api', () => ({
    fetchClient: jest.fn(),
    fetchForms: jest.fn(),
    fetchBots: jest.fn(),
    fetchChats: jest.fn(),
    fetchChannels: jest.fn(),
    fetchFormsForChannels: jest.fn(),
    fetchBotsForChannels: jest.fn(),
    fetchBotsForChats: jest.fn(),
    fetchChatsForChannels: jest.fn(),
    apiDeleteChat: jest.fn(),
}));

const createDeferred = () => {
    let resolve;
    const promise = new Promise((res) => {
        resolve = res;
    });

    return {promise, resolve};
};

describe('App chat ownership', () => {
    beforeEach(() => {
        jest.clearAllMocks();

        fetchClient.mockResolvedValue([]);
        fetchForms.mockResolvedValue([]);
        fetchBots.mockResolvedValue([]);
        fetchChannels.mockResolvedValue([]);
        fetchFormsForChannels.mockResolvedValue([]);
        fetchBotsForChannels.mockResolvedValue([]);
        fetchChatsForChannels.mockResolvedValue([]);
        apiDeleteChat.mockResolvedValue({});
    });

    it('does not delete chats while chats and relations load separately', async () => {
        const chatsDeferred = createDeferred();
        const relationsDeferred = createDeferred();

        fetchChats.mockReturnValueOnce(chatsDeferred.promise);
        fetchBotsForChats.mockReturnValueOnce(relationsDeferred.promise);

        render(<App />);

        await act(async () => {
            chatsDeferred.resolve([{id: 101, title: {rendered: 'Chat 101'}}]);
            await Promise.resolve();
        });

        expect(apiDeleteChat).not.toHaveBeenCalled();

        await act(async () => {
            relationsDeferred.resolve([{data: {to: 101, meta: {status: ['active']}}}]);
            await Promise.resolve();
        });

        await act(async () => {
            await Promise.resolve();
        });

        expect(apiDeleteChat).not.toHaveBeenCalled();
    });

    it('does not delete orphan chats after both datasets load', async () => {
        fetchChats.mockResolvedValueOnce([
            {id: 101, title: {rendered: 'Connected chat'}},
            {id: 202, title: {rendered: 'Orphan chat'}},
        ]);
        fetchBotsForChats.mockResolvedValueOnce([
            {data: {to: 101, meta: {status: ['active']}}},
        ]);

        render(<App />);

        await waitFor(() => expect(screen.getByText('Bots')).toBeInTheDocument());

        await act(async () => {
            await Promise.resolve();
        });

        expect(apiDeleteChat).not.toHaveBeenCalled();
    });
});

describe('App migration recovery', () => {
    beforeEach(() => {
        jest.clearAllMocks();

        global.cf7TelegramData = {
            intervals: {
                ping: 5000,
                bot_fetch: 12000,
            },
            migration: {
                show_action_button: false,
            },
        };

        fetchClient.mockResolvedValue([]);
        fetchForms.mockResolvedValue([]);
        fetchBots.mockResolvedValue([]);
        fetchChats.mockResolvedValue([]);
        fetchChannels.mockResolvedValue([]);
        fetchFormsForChannels.mockResolvedValue([]);
        fetchBotsForChannels.mockResolvedValue([]);
        fetchBotsForChats.mockResolvedValue([]);
        fetchChatsForChannels.mockResolvedValue([]);
        apiDeleteChat.mockResolvedValue({});
    });

    it('shows failed migration retry even when migrated bot and channel records exist', async () => {
        global.cf7TelegramData.migration = {
            show_action_button: true,
            action_url: 'https://example.test/wp-admin/admin-post.php',
            nonce: 'nonce',
            status: {
                is_failed: true,
                can_retry: true,
                last_error: {
                    category: 'migration_failed',
                    message: 'A migration step could not be completed.',
                },
            },
        };
        fetchBots.mockResolvedValueOnce([{id: 10, title: {rendered: 'Bot'}}]);
        fetchChannels.mockResolvedValueOnce([{id: 20, title: {rendered: 'Channel'}}]);

        render(<App />);

        expect(await screen.findByText('Retry migration')).toBeEnabled();
        expect(screen.getByText('A migration step could not be completed.')).toBeInTheDocument();
    });

    it('does not render an unclassified raw migration error', async () => {
        global.cf7TelegramData.migration = {
            show_action_button: true,
            action_url: 'https://example.test/wp-admin/admin-post.php',
            nonce: 'nonce',
            status: {
                is_failed: true,
                can_retry: true,
                last_error: {
                    message: 'SQL failed with token 123456789:SECRET_TOKEN',
                },
            },
        };

        render(<App />);

        expect(await screen.findByText('Retry migration')).toBeEnabled();
        expect(screen.queryByText(/SECRET_TOKEN/)).not.toBeInTheDocument();
    });

    it('disables migration submission while retry is already scheduled', async () => {
        global.cf7TelegramData.migration = {
            show_action_button: true,
            action_url: 'https://example.test/wp-admin/admin-post.php',
            nonce: 'nonce',
            status: {
                is_scheduled: true,
                can_retry: false,
                last_error: {},
            },
        };

        render(<App />);

        expect(await screen.findByText('Run migration')).toBeDisabled();
    });
});

describe('App resource loading', () => {
    beforeEach(() => {
        jest.clearAllMocks();

        fetchClient.mockResolvedValue([]);
        fetchForms.mockResolvedValue([]);
        fetchBots.mockResolvedValue([{id: 10, title: {rendered: 'Bot'}}]);
        fetchChats.mockResolvedValue([]);
        fetchChannels.mockResolvedValue([{id: 20, title: {rendered: 'Channel'}}]);
        fetchFormsForChannels.mockResolvedValue([]);
        fetchBotsForChannels.mockResolvedValue([]);
        fetchBotsForChats.mockResolvedValue([]);
        fetchChatsForChannels.mockResolvedValue([]);
    });

    it('uses bridge terminology for the routing entity section', async () => {
        render(<App />);

        expect(await screen.findByText('Bridges')).toBeInTheDocument();
        expect(screen.getByText('A bridge is required to run the integration. Create at least one bridge, and add more when different forms should send messages to different sets of Telegram recipients.')).toBeInTheDocument();
    });

    it('shows bot setup guidance with the Telegram creation guide link', async () => {
        render(<App />);

        expect(await screen.findByText('Bots')).toBeInTheDocument();
        expect(screen.getByText('A Telegram bot is required to run the integration. Create at least one bot, then connect it here.')).toBeInTheDocument();
        expect(screen.getByRole('link', {name: 'How to create a bot'})).toHaveAttribute('href', 'https://core.telegram.org/bots#3-how-do-i-create-a-bot');
    });

    it('keeps successful resources visible and retries only failed requests', async () => {
        fetchForms.mockRejectedValueOnce(new Error('forms endpoint failed'));

        render(<App />);

        expect(await screen.findByText('Bot 10')).toBeInTheDocument();
        expect(screen.getByText('Channel 20')).toBeInTheDocument();
        expect(screen.getByText('Some settings data could not be loaded.')).toBeInTheDocument();
        expect(screen.getByTestId('channel-20-forms-state')).toHaveTextContent('error');

        fireEvent.click(screen.getByText('Retry failed requests'));

        await waitFor(() => {
            expect(fetchForms).toHaveBeenCalledTimes(2);
            expect(screen.queryByText('Some settings data could not be loaded.')).not.toBeInTheDocument();
            expect(screen.getByTestId('channel-20-forms-state')).toHaveTextContent('ready');
        });

        expect(fetchBots).toHaveBeenCalledTimes(1);
        expect(fetchChannels).toHaveBeenCalledTimes(1);
    });

    it('does not interpret failed relation data as an empty successful list', async () => {
        fetchFormsForChannels.mockRejectedValueOnce(new Error('relation endpoint failed'));

        render(<App />);

        expect(await screen.findByText('Channel 20')).toBeInTheDocument();
        expect(screen.getByTestId('channel-20-forms-state')).toHaveTextContent('error');
        expect(screen.getByText('Some settings data could not be loaded.')).toBeInTheDocument();
    });
});

describe('SettingsErrorBoundary', () => {
    it('contains render failures and exposes a retry action', () => {
        const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
        const Broken = () => {
            throw new Error('render failed');
        };

        render(
            <SettingsErrorBoundary>
                <Broken />
            </SettingsErrorBoundary>
        );

        expect(screen.getByText('The settings screen could not be displayed.')).toBeInTheDocument();
        expect(screen.getByText('Try again')).toBeInTheDocument();
        consoleError.mockRestore();
    });
});
