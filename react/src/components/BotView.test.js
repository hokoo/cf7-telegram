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
                renderEditTokenCount={{current: 0}}
            />
        );

        const botName = screen.getByTitle('Click to copy bot name');

        expect(botName).toHaveTextContent(`@${truncatedName}...`);

        fireEvent.click(botName);

        await waitFor(() => {
            expect(navigator.clipboard.writeText).toHaveBeenCalledWith(`@${longName}`);
        });
    });
});
