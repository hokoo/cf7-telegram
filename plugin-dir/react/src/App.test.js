import React from 'react';
import {act, render, screen, waitFor} from '@testing-library/react';
import App from './App';
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

jest.mock('./components/Channel', () => () => <div>Channel</div>);
jest.mock('./components/Bot', () => () => <div>Bot</div>);
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
                bot_fetch: 30000,
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
                    message: 'Synthetic migration failure.',
                },
            },
        };
        fetchBots.mockResolvedValueOnce([{id: 10, title: {rendered: 'Bot'}}]);
        fetchChannels.mockResolvedValueOnce([{id: 20, title: {rendered: 'Channel'}}]);

        render(<App />);

        expect(await screen.findByText('Retry migration')).toBeEnabled();
        expect(screen.getByText('Synthetic migration failure.')).toBeInTheDocument();
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
