import React from 'react';
import {fireEvent, render, screen, waitFor} from '@testing-library/react';
import {createChannel} from '../utils/main';
import NewChannel from './NewChannel';

jest.mock('../utils/main', () => ({
    createChannel: jest.fn(),
}));

describe('NewChannel bridge copy', () => {
    beforeEach(() => {
        jest.clearAllMocks();
    });

    it('creates a bridge with the bridge default title', async () => {
        const setChannels = jest.fn();
        createChannel.mockResolvedValueOnce({id: 20});

        render(<NewChannel setChannels={setChannels} />);

        const button = screen.getByRole('button', {name: 'Create Bridge'});
        fireEvent.click(button);

        await waitFor(() => {
            expect(createChannel).toHaveBeenCalledWith('Bridge Name', setChannels);
        });
    });

    it('shows bridge wording when creation fails', async () => {
        const consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
        const alert = jest.spyOn(window, 'alert').mockImplementation(() => {});

        createChannel.mockRejectedValueOnce(new Error('create failed'));

        render(<NewChannel setChannels={jest.fn()} />);

        fireEvent.click(screen.getByRole('button', {name: 'Create Bridge'}));

        await waitFor(() => {
            expect(alert).toHaveBeenCalledWith('Failed to create bridge');
        });

        consoleError.mockRestore();
        alert.mockRestore();
    });
});
