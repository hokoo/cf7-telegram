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
