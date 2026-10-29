import React from 'react';
import {fireEvent, render, screen, waitFor} from '@testing-library/react';
import BotView from './BotView';

describe('BotView copyable bot name', () => {
    const originalClipboard = navigator.clipboard;
    const originalSecureContext = window.isSecureContext;

    beforeEach(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: {
                writeText: jest.fn().mockResolvedValue(undefined),
            },
        });

        Object.defineProperty(window, 'isSecureContext', {
            configurable: true,
            value: true,
        });
    });

    afterEach(() => {
        Object.defineProperty(navigator, 'clipboard', {
            configurable: true,
            value: originalClipboard,
        });

        Object.defineProperty(window, 'isSecureContext', {
            configurable: true,
            value: originalSecureContext,
        });
    });

    it('copies the full bot name when the visible name is truncated', async () => {
        const longName = 'super_long_telegram_bot_name';
        const truncatedName = longName.slice(0, 18);

        render(
            <BotView
                bot={{id: 1, isTokenDefinedByConst: false, phpConst: 'CF7TG_TOKEN'}}
                chatsForBot={[]}
                bot2ChatConnections={[]}
                updatingStatusIds={[]}
                isEditingToken={false}
                nameValue={longName}
                isTokenEmpty={false}
                tokenValue="1234567890"
                saving={false}
                error=""
                handleEditToken={jest.fn()}
                deleteBot={jest.fn()}
                handleKeyDown={jest.fn()}
                setTokenValue={jest.fn()}
                handleToggleChatStatus={jest.fn()}
                handleDisconnectChat={jest.fn()}
                online={true}
            />
        );

        const botName = screen.getByTitle('Click to copy bot name');

        expect(botName).toHaveTextContent(`@${truncatedName}...`);

        fireEvent.click(botName);

        await waitFor(() => {
            expect(navigator.clipboard.writeText).toHaveBeenCalledWith(`@${longName}`);
        });
    });

    it('copies the command addressed to the current bot', async () => {
        render(<BotView bot={{id: 1}} nameValue="current_bot" isTokenEmpty tokenValue="" online />);

        const command = screen.getByTitle('Click to copy bot command');
        expect(command).toHaveTextContent('/cf7tg_start@current_bot');
        fireEvent.click(command);
        await waitFor(() => expect(navigator.clipboard.writeText).toHaveBeenCalledWith('/cf7tg_start@current_bot'));
    });

    it('keeps same-name topic identities visible in pending and rename states', () => {
        render(<BotView
            bot={{id: 1}}
            nameValue="current_bot"
            isTokenEmpty
            tokenValue=""
            online
            chatsForBot={[
                {id: 10, title: {rendered: 'Same label'}, customName: 'Same label', telegramName: 'Operations', messageThreadID: 21},
                {id: 11, title: {rendered: 'Same label'}, customName: 'Same label', telegramName: 'Support', messageThreadID: 22},
            ]}
            bot2ChatConnections={[
                {data: {from: 1, to: 10, meta: {status: ['pending']}}},
                {data: {from: 1, to: 11, meta: {status: ['active']}}},
            ]}
            renamingChatId={11}
            chatNameValue="Custom"
        />);

        expect(screen.getByTestId('cf7tg-bot-1-chat-10').querySelector('.chat-name')).toHaveTextContent('Operations');
        expect(screen.getByTestId('cf7tg-bot-1-chat-10').querySelector('.topic-identity')).toHaveTextContent('Same label');
        expect(screen.getByTestId('cf7tg-bot-1-chat-10').querySelector('.topic-identity')).toHaveAttribute('title', 'Topic #21');
        expect(screen.getByTestId('cf7tg-bot-1-chat-10').querySelector('.rename-chat')).toBeInTheDocument();
        expect(screen.getByTestId('cf7tg-bot-1-chat-11').querySelector('.chat-name')).toHaveTextContent('Support');
        expect(screen.getByTestId('cf7tg-bot-1-chat-11-name-input')).toHaveValue('Custom');
    });

    it('keeps the group name visible while renaming an uncustomized topic', () => {
        render(<BotView
            bot={{id: 1}}
            nameValue="current_bot"
            isTokenEmpty
            tokenValue=""
            online
            chatsForBot={[{id: 12, title: {rendered: 'Operations'}, customName: '', telegramName: 'Operations', messageThreadID: 23}]}
            bot2ChatConnections={[{data: {from: 1, to: 12, meta: {status: ['active']}}}]}
            renamingChatId={12}
            chatNameValue="New label"
        />);

        expect(screen.getByTestId('cf7tg-bot-1-chat-12-name-input')).toHaveValue('New label');
        expect(screen.getByTestId('cf7tg-bot-1-chat-12').querySelector('.chat-name')).toHaveTextContent('Operations');
    });

    it('shows a Telegram topic name when known and its ID as a fallback', () => {
        render(<BotView
            bot={{id: 1}}
            nameValue="current_bot"
            isTokenEmpty
            tokenValue=""
            online
            chatsForBot={[
                {id: 13, title: {rendered: 'Forum'}, telegramName: 'Forum', telegramTopicName: 'Questions', messageThreadID: 24},
                {id: 14, title: {rendered: 'Forum'}, telegramName: 'Forum', messageThreadID: 25},
            ]}
            bot2ChatConnections={[
                {data: {from: 1, to: 13, meta: {status: ['active']}}},
                {data: {from: 1, to: 14, meta: {status: ['active']}}},
            ]}
        />);

        expect(screen.getByTestId('cf7tg-bot-1-chat-13').querySelector('.topic-identity')).toHaveTextContent('Questions');
        expect(screen.getByTestId('cf7tg-bot-1-chat-14').querySelector('.topic-identity')).toHaveTextContent('Topic #25');
    });

    it('does not mutate token state while rendering edit mode', () => {
        const setTokenValue = jest.fn();
        const originalToWellFormed = String.prototype.toWellFormed;

        try {
            delete String.prototype.toWellFormed;

            render(
                <BotView
                    bot={{id: 1, isTokenDefinedByConst: false, phpConst: 'CF7TG_TOKEN'}}
                    chatsForBot={[{id: 10, title: {rendered: 'Test chat'}}]}
                    bot2ChatConnections={[{data: {from: 1, to: 10, muted: false}}]}
                    updatingStatusIds={[]}
                    isEditingToken={true}
                    nameValue="test_bot"
                    isTokenEmpty={false}
                    tokenValue=""
                    saving={false}
                    error=""
                    handleEditToken={jest.fn()}
                    deleteBot={jest.fn()}
                    handleKeyDown={jest.fn()}
                    setTokenValue={setTokenValue}
                    handleToggleChatStatus={jest.fn()}
                    handleDisconnectChat={jest.fn()}
                    online={true}
                />
            );

            expect(setTokenValue).not.toHaveBeenCalled();
            expect(screen.getByTitle('Active')).toHaveTextContent('Test chat');
        } finally {
            if (originalToWellFormed) {
                String.prototype.toWellFormed = originalToWellFormed;
            }
        }
    });

    it('renders chat rename mode with save, restore, and cancel actions', () => {
        const handleRenameChatChange = jest.fn();
        const handleRenameChatKeyDown = jest.fn();
        const handleSaveChatName = jest.fn();
        const handleRestoreChatName = jest.fn();
        const handleCancelRenameChat = jest.fn();

        render(
            <BotView
                bot={{id: 1, isTokenDefinedByConst: false, phpConst: 'CF7TG_TOKEN'}}
                chatsForBot={[{id: 10, title: {rendered: 'Original chat'}}]}
                bot2ChatConnections={[{data: {from: 1, to: 10, muted: false}}]}
                updatingStatusIds={[]}
                updatingNameIds={[]}
                renamingChatId={10}
                chatNameValue="Custom chat"
                isEditingToken={false}
                nameValue="test_bot"
                isTokenEmpty={false}
                tokenValue="1234"
                saving={false}
                error=""
                handleEditToken={jest.fn()}
                deleteBot={jest.fn()}
                handleKeyDown={jest.fn()}
                setTokenValue={jest.fn()}
                handleToggleChatStatus={jest.fn()}
                handleDisconnectChat={jest.fn()}
                handleRenameChatChange={handleRenameChatChange}
                handleRenameChatKeyDown={handleRenameChatKeyDown}
                handleSaveChatName={handleSaveChatName}
                handleRestoreChatName={handleRestoreChatName}
                handleCancelRenameChat={handleCancelRenameChat}
                online={true}
            />
        );

        const input = screen.getByTestId('cf7tg-bot-1-chat-10-name-input');
        expect(input).toHaveValue('Custom chat');

        fireEvent.change(input, {target: {value: 'Next chat'}});
        expect(handleRenameChatChange).toHaveBeenCalled();

        fireEvent.keyDown(input, {key: 'Enter'});
        expect(handleRenameChatKeyDown).toHaveBeenCalledWith(10, expect.objectContaining({key: 'Enter'}));

        fireEvent.click(screen.getByText('Save'));
        fireEvent.click(screen.getByText('Restore'));
        fireEvent.click(screen.getByText('Cancel'));

        expect(handleSaveChatName).toHaveBeenCalledWith(10);
        expect(handleRestoreChatName).toHaveBeenCalledWith(10);
        expect(handleCancelRenameChat).toHaveBeenCalled();
    });

    it('does not render rename action for a pending chat', () => {
        render(
            <BotView
                bot={{id: 1, isTokenDefinedByConst: false, phpConst: 'CF7TG_TOKEN'}}
                chatsForBot={[{id: 10, title: {rendered: 'Pending chat'}}]}
                bot2ChatConnections={[{data: {from: 1, to: 10, meta: {status: ['pending']}}}]}
                updatingStatusIds={[]}
                updatingNameIds={[]}
                isEditingToken={false}
                nameValue="test_bot"
                isTokenEmpty={false}
                tokenValue="1234"
                saving={false}
                error=""
                handleEditToken={jest.fn()}
                deleteBot={jest.fn()}
                handleKeyDown={jest.fn()}
                setTokenValue={jest.fn()}
                handleToggleChatStatus={jest.fn()}
                handleDisconnectChat={jest.fn()}
                online={true}
            />
        );

        expect(screen.queryByText('Rename')).not.toBeInTheDocument();
        expect(screen.getByText('Activate')).toBeInTheDocument();
        expect(screen.getByText('Remove')).toBeInTheDocument();
    });
});
