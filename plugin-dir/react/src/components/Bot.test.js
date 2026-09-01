import React from 'react';
import {act, render, waitFor} from '@testing-library/react';
import {
    apiFetchUpdates,
    apiPingBot,
    apiRenameChat,
    apiRestoreChatName,
    apiUpdateBotToken,
    fetchBot
} from '../utils/api';
import {connectChat2Channel, setBot2ChatConnectionStatus} from '../utils/main';
import Bot, {getUpdateDiagnostic, saveBotTokenTransactionally, updateBotChatStatus} from './Bot';

let mockBotViewProps;

jest.mock('./BotView', () => (props) => {
    mockBotViewProps = props;
    return null;
});

jest.mock('../utils/api', () => ({
    apiDeleteBot: jest.fn(),
    apiFetchUpdates: jest.fn(),
    apiPingBot: jest.fn(),
    apiRenameChat: jest.fn(),
    apiRestoreChatName: jest.fn(),
    apiUpdateBotToken: jest.fn(),
    fetchBot: jest.fn(),
}));

jest.mock('../utils/main', () => ({
    connectChat2Channel: jest.fn(),
    disconnectConnectionBot2Chat: jest.fn(),
    setBot2ChatConnectionStatus: jest.fn(),
}));

const renderBot = () => render(
    <Bot
        bot={{
            id: 1,
            title: {rendered: 'test_bot'},
            token: '1234',
            isTokenEmpty: false,
            isTokenDefinedByConst: false,
        }}
        chats={[]}
        bot2ChatConnections={[]}
        setBots={jest.fn()}
        setBot2ChatConnections={jest.fn()}
        bot2ChannelConnections={[]}
        setBot2ChannelConnections={jest.fn()}
        setChat2ChannelConnections={jest.fn()}
        loadChatData={jest.fn()}
    />
);

describe('saveBotTokenTransactionally', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        apiUpdateBotToken.mockResolvedValue({identityChanged: false, relationsReset: false});
    });

    it('leaves client relations untouched when token validation fails', async () => {
        apiUpdateBotToken.mockRejectedValueOnce(new Error('Invalid token'));
        const pingBot = jest.fn();
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await expect(saveBotTokenTransactionally({
            botId: 1,
            token: '  new-token  ',
            pingBot,
            bot2ChatConnections: [
                {data: {id: 11, from: 1, to: 101}},
                {data: {id: 12, from: 2, to: 202}},
            ],
            setBot2ChatConnections,
            bot2ChannelConnections: [
                {data: {id: 21, from: 1, to: 301}},
                {data: {id: 22, from: 2, to: 302}},
            ],
            setBot2ChannelConnections,
        })).rejects.toThrow('Invalid token');

        expect(apiUpdateBotToken).toHaveBeenCalledWith(1, 'new-token');
        expect(pingBot).not.toHaveBeenCalled();
        expect(setBot2ChatConnections).not.toHaveBeenCalled();
        expect(setBot2ChannelConnections).not.toHaveBeenCalled();
    });

    it('preserves relations when the token still belongs to the same bot', async () => {
        const pingBot = jest.fn().mockResolvedValue(false);
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await saveBotTokenTransactionally({
            botId: 1,
            token: 'new-token',
            pingBot,
            bot2ChatConnections: [
                {data: {id: 11, from: 1, to: 101}},
                {data: {id: 12, from: 2, to: 202}},
            ],
            setBot2ChatConnections,
            bot2ChannelConnections: [
                {data: {id: 21, from: 1, to: 301}},
                {data: {id: 22, from: 2, to: 302}},
            ],
            setBot2ChannelConnections,
        });

        expect(setBot2ChatConnections).not.toHaveBeenCalled();
        expect(setBot2ChannelConnections).not.toHaveBeenCalled();
        expect(pingBot).toHaveBeenCalledWith({force: true, skipEditingCheck: true});
    });

    it('removes only current bot relations from client state after server reset', async () => {
        apiUpdateBotToken.mockResolvedValueOnce({identityChanged: true, relationsReset: true});
        const setBot2ChatConnections = jest.fn();
        const setBot2ChannelConnections = jest.fn();

        await saveBotTokenTransactionally({
            botId: 1,
            token: 'new-token',
            pingBot: jest.fn().mockResolvedValue(true),
            bot2ChatConnections: [],
            setBot2ChatConnections,
            bot2ChannelConnections: [],
            setBot2ChannelConnections,
        });

        const chatUpdater = setBot2ChatConnections.mock.calls[0][0];
        const channelUpdater = setBot2ChannelConnections.mock.calls[0][0];
        expect(chatUpdater([{data: {from: 1}}, {data: {from: 2}}])).toEqual([{data: {from: 2}}]);
        expect(channelUpdater([{data: {from: 1}}, {data: {from: 2}}])).toEqual([{data: {from: 2}}]);
    });
});

describe('getUpdateDiagnostic', () => {
    it('distinguishes webhook conflicts, transient transport failures, and update errors', () => {
        expect(getUpdateDiagnostic({hasWebhookConflict: true, errors: []})).toBe('webhook_conflict');
        expect(getUpdateDiagnostic({hasWebhookConflict: false, errors: [{errorType: 'transport'}]})).toBe('transient_update_error');
        expect(getUpdateDiagnostic({hasWebhookConflict: false, errors: [{errorType: 'http'}]})).toBe('update_error');
        expect(getUpdateDiagnostic({
            hasWebhookConflict: false,
            errors: [{errorType: 'transport'}, {errorType: 'telegram'}],
        })).toBe('update_error');
        expect(getUpdateDiagnostic({hasWebhookConflict: false, errors: []})).toBeNull();
    });
});

describe('Bot update polling diagnostics', () => {
    const successfulUpdate = {
        hasWebhookConflict: false,
        hasNewConnections: false,
        hasNewChats: false,
        errors: [],
    };
    let originalFetchInterval;

    beforeEach(() => {
        jest.clearAllMocks();
        mockBotViewProps = null;
        originalFetchInterval = cf7TelegramData.intervals.bot_fetch;
        apiPingBot.mockResolvedValue({online: true, botName: 'test_bot'});
        fetchBot.mockResolvedValue({title: {rendered: 'test_bot'}});
    });

    afterEach(() => {
        cf7TelegramData.intervals.bot_fetch = originalFetchInterval;
    });

    it('does not show a persistent error for a transient Telegram transport timeout', async () => {
        apiFetchUpdates.mockResolvedValue({
            ...successfulUpdate,
            errors: [{errorType: 'transport'}],
        });

        const view = renderBot();

        await waitFor(() => expect(apiFetchUpdates).toHaveBeenCalledTimes(1));
        expect(mockBotViewProps.error).toBeNull();

        view.unmount();
    });

    it('shows an error while Telegram transport failures remain sustained', async () => {
        cf7TelegramData.intervals.bot_fetch = 50;
        apiFetchUpdates.mockResolvedValue({
            ...successfulUpdate,
            errors: [{errorType: 'transport'}],
        });

        const view = renderBot();

        await waitFor(() => expect(apiFetchUpdates).toHaveBeenCalledTimes(3));
        await waitFor(() => expect(mockBotViewProps.error).toBe('Telegram updates could not be checked.'));

        view.unmount();
    });

    it('clears an actionable update error after the next successful poll', async () => {
        cf7TelegramData.intervals.bot_fetch = 100;
        apiFetchUpdates
            .mockResolvedValueOnce({...successfulUpdate, errors: [{errorType: 'telegram'}]})
            .mockResolvedValue(successfulUpdate);

        const view = renderBot();

        await waitFor(() => expect(mockBotViewProps.error).toBe('Telegram updates could not be checked.'));
        await waitFor(() => expect(apiFetchUpdates).toHaveBeenCalledTimes(2));
        await waitFor(() => expect(mockBotViewProps.error).toBeNull());

        view.unmount();
    });
});

describe('updateBotChatStatus', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('does not connect a pending chat when the status mutation fails', async () => {
        setBot2ChatConnectionStatus.mockRejectedValueOnce(new Error('status failed'));

        await expect(updateBotChatStatus({
            connectionId: 11,
            newStatus: 'active',
            currentStatus: 'pending',
            chatId: 101,
            botId: 1,
            bot2ChannelConnections: [{data: {from: 1, to: 20}}],
            setBot2ChatConnections: jest.fn(),
            setChat2ChannelConnections: jest.fn(),
        })).rejects.toThrow('status failed');

        expect(connectChat2Channel).not.toHaveBeenCalled();
    });

    it('connects a pending chat only after the status mutation succeeds', async () => {
        setBot2ChatConnectionStatus.mockResolvedValueOnce({id: 11});
        connectChat2Channel.mockResolvedValueOnce({id: 12});

        await updateBotChatStatus({
            connectionId: 11,
            newStatus: 'active',
            currentStatus: 'pending',
            chatId: 101,
            botId: 1,
            bot2ChannelConnections: [{data: {from: 1, to: 20}}],
            setBot2ChatConnections: jest.fn(),
            setChat2ChannelConnections: jest.fn(),
        });

        expect(setBot2ChatConnectionStatus).toHaveBeenCalledTimes(1);
        expect(connectChat2Channel).toHaveBeenCalledTimes(1);
        expect(setBot2ChatConnectionStatus.mock.invocationCallOrder[0])
            .toBeLessThan(connectChat2Channel.mock.invocationCallOrder[0]);
    });
});

describe('Bot token editing', () => {
    let timeoutSpy;

    beforeEach(() => {
        jest.clearAllMocks();
        mockBotViewProps = null;
        apiPingBot.mockResolvedValue({online: false, botName: 'test_bot'});
        timeoutSpy = jest.spyOn(global, 'setTimeout').mockImplementation(() => 0);
    });

    afterEach(() => {
        timeoutSpy.mockRestore();
    });

    it('initializes edit state outside BotView render and restores it on cancel', async () => {
        let view;
        await act(async () => {
            view = render(
                <Bot
                    bot={{
                        id: 1,
                        title: {rendered: 'test_bot'},
                        token: '1234',
                        isTokenEmpty: false,
                        isTokenDefinedByConst: false,
                    }}
                    chats={[]}
                    bot2ChatConnections={[]}
                    setBots={jest.fn()}
                    setBot2ChatConnections={jest.fn()}
                    bot2ChannelConnections={[]}
                    setBot2ChannelConnections={jest.fn()}
                    setChat2ChannelConnections={jest.fn()}
                    loadChatData={jest.fn()}
                />
            );
            await Promise.resolve();
        });

        expect(mockBotViewProps).not.toBeNull();

        act(() => mockBotViewProps.handleEditToken());

        expect(mockBotViewProps.isEditingToken).toBe(true);
        expect(mockBotViewProps.tokenValue).toBe('');

        act(() => mockBotViewProps.handleKeyDown({key: 'Escape'}));

        expect(mockBotViewProps.isEditingToken).toBe(false);
        expect(mockBotViewProps.tokenValue).toBe('1234');

        view.unmount();
    });
});

describe('Bot chat rename controls', () => {
    let timeoutSpy;

    beforeEach(() => {
        jest.clearAllMocks();
        mockBotViewProps = null;
        apiPingBot.mockResolvedValue({online: false, botName: 'test_bot'});
        timeoutSpy = jest.spyOn(global, 'setTimeout').mockImplementation(() => 0);
    });

    afterEach(() => {
        timeoutSpy.mockRestore();
    });

    const renderBotWithChat = (setChats = jest.fn()) => render(
        <Bot
            bot={{
                id: 1,
                title: {rendered: 'test_bot'},
                token: '1234',
                isTokenEmpty: false,
                isTokenDefinedByConst: false,
            }}
            chats={[{id: 10, title: {rendered: 'Original chat'}}]}
            setChats={setChats}
            bot2ChatConnections={[{data: {id: 100, from: 1, to: 10, meta: {status: ['active']}}}]}
            setBots={jest.fn()}
            setBot2ChatConnections={jest.fn()}
            bot2ChannelConnections={[]}
            setBot2ChannelConnections={jest.fn()}
            setChat2ChannelConnections={jest.fn()}
            loadChatData={jest.fn()}
        />
    );

    it('renames a chat and updates shared chat state', async () => {
        const setChats = jest.fn();
        apiRenameChat.mockResolvedValue({id: 10, title: {rendered: 'Support desk'}, customName: 'Support desk'});

        const view = renderBotWithChat(setChats);
        expect(mockBotViewProps.chatsForBot).toHaveLength(1);

        act(() => mockBotViewProps.handleStartRenameChat(mockBotViewProps.chatsForBot[0]));
        expect(mockBotViewProps.renamingChatId).toBe(10);
        expect(mockBotViewProps.chatNameValue).toBe('Original chat');

        act(() => mockBotViewProps.handleRenameChatChange({target: {value: 'Support desk'}}));
        await act(async () => {
            await mockBotViewProps.handleSaveChatName(10);
        });

        expect(apiRenameChat).toHaveBeenCalledWith(10, 1, 'Support desk');
        const updater = setChats.mock.calls[0][0];
        expect(updater([
            {id: 10, title: {rendered: 'Original chat'}},
            {id: 11, title: {rendered: 'Other chat'}},
        ])).toEqual([
            {id: 10, title: {rendered: 'Support desk'}, customName: 'Support desk'},
            {id: 11, title: {rendered: 'Other chat'}},
        ]);
        expect(mockBotViewProps.renamingChatId).toBeNull();

        view.unmount();
    });

    it('restores the current Telegram chat name through the bot', async () => {
        const setChats = jest.fn();
        apiRestoreChatName.mockResolvedValue({id: 10, title: {rendered: 'Alice Current'}, customName: ''});

        const view = renderBotWithChat(setChats);
        expect(mockBotViewProps.chatsForBot).toHaveLength(1);

        act(() => mockBotViewProps.handleStartRenameChat(mockBotViewProps.chatsForBot[0]));
        await act(async () => {
            await mockBotViewProps.handleRestoreChatName(10);
        });

        expect(apiRestoreChatName).toHaveBeenCalledWith(10, 1);
        const updater = setChats.mock.calls[0][0];
        expect(updater([{id: 10, title: {rendered: 'Manual'}}])).toEqual([
            {id: 10, title: {rendered: 'Alice Current'}, customName: ''},
        ]);
        expect(mockBotViewProps.renamingChatId).toBeNull();

        view.unmount();
    });
});
