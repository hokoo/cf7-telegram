import React from 'react';
import {act, render, waitFor} from '@testing-library/react';
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

jest.mock('./components/Settings', () => () => <div>Settings</div>);
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

describe('App chat garbage collector', () => {
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

    it('waits for chats and relations before deleting chats', async () => {
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

    it('still deletes orphan chats after both datasets load', async () => {
        fetchChats.mockResolvedValueOnce([
            {id: 101, title: {rendered: 'Connected chat'}},
            {id: 202, title: {rendered: 'Orphan chat'}},
        ]);
        fetchBotsForChats.mockResolvedValueOnce([
            {data: {to: 101, meta: {status: ['active']}}},
        ]);

        render(<App />);

        await waitFor(() => expect(apiDeleteChat).toHaveBeenCalledWith(202));

        await act(async () => {
            await Promise.resolve();
        });
    });
});
