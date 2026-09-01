import React from 'react';
import {render, screen} from '@testing-library/react';
import ChannelView from './ChannelView';

const renderChannelView = (props = {}) => render(
    <ChannelView
        channel={{id: 20}}
        titleValue="Bridge Name"
        saving={false}
        error=""
        handleTitleClick={jest.fn()}
        handleTitleChange={jest.fn()}
        handleKeyDown={jest.fn()}
        handleCancelEdit={jest.fn()}
        saveTitle={jest.fn()}
        deleteChannel={jest.fn()}
        getToggleButtonLabel={jest.fn()}
        dataAvailability={{forms: 'ready', bots: 'ready', chats: 'ready'}}
        {...props}
    />
);

describe('ChannelView bridge copy', () => {
    it('uses bridge wording for empty states and removal', () => {
        renderChannelView();

        expect(screen.getByText('[No chats assigned to this bridge]')).toBeInTheDocument();
        expect(screen.getByText('[No forms assigned to this bridge]')).toBeInTheDocument();
        expect(screen.getByRole('button', {name: 'Remove Bridge'})).toBeInTheDocument();
    });
});
