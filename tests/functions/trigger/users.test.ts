import { trigger } from '../../../src/functions/trigger/users';
import * as userService from '../../../src/services/userService';
import * as eventService from '../../../src/services/eventService';

jest.mock('../../../src/services/userService');
jest.mock('../../../src/services/eventService');

describe('users.update trigger', () => {
  const mockUser = {
    $id: '656ae11125ca984676a9',
    $createdAt: '2023-12-02T07:47:29.156+00:00',
    $updatedAt: '2023-12-02T18:52:15.750+00:00',
    name: '',
    password: '..',
    hash: 'argon2',
    hashOptions: {
      type: 'argon2',
      memoryCost: 2048,
      timeCost: 4,
      threads: 3,
    },
    registration: '2023-12-02T07:47:29.155+00:00',
    status: true,
    labels: [],
    passwordUpdate: '2023-12-02T07:47:29.155+00:00',
    email: 'admin123@test.com',
    phone: '',
    emailVerification: false,
    phoneVerification: false,
    prefs: {
      test: 'value',
    },
    accessedAt: '2023-12-02T07:47:29.155+00:00',
  };

  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('should send full user object for preference updates', async () => {
    (userService.getUser as jest.Mock).mockResolvedValue(mockUser);
    (eventService.sendEvent as jest.Mock).mockResolvedValue(undefined);

    const event = {
      type: 'prefs',
      userId: '656ae11125ca984676a9',
      data: { test: 'value', test2: 'val2' },
    };

    await trigger(event);

    expect(userService.getUser).toHaveBeenCalledWith('656ae11125ca984676a9');
    expect(eventService.sendEvent).toHaveBeenCalledWith(
      'users.656ae11125ca984676a9.update',
      {
        ...mockUser,
        test: 'value',
        test2: 'val2',
      }
    );
  });

  it('should send full user object for email updates', async () => {
    (userService.getUser as jest.Mock).mockResolvedValue(mockUser);
    (eventService.sendEvent as jest.Mock).mockResolvedValue(undefined);

    const event = {
      type: 'email',
      userId: '656ae11125ca984676a9',
      data: { email: 'new@example.com' },
    };

    await trigger(event);

    expect(userService.getUser).toHaveBeenCalledWith('656ae11125ca984676a9');
    expect(eventService.sendEvent).toHaveBeenCalledWith(
      'users.656ae11125ca984676a9.update',
      {
        ...mockUser,
        email: 'new@example.com',
      }
    );
  });
});
