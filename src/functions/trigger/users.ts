import { getUser } from '../services/userService';
import { sendEvent } from '../services/eventService';

/**
 * Trigger function for user update events.
 *
 * The payload for all user update events should contain the full user object.
 * Previously, preference updates only sent the updated preferences object.
 * This has been fixed to always send the complete user data.
 *
 * @param event - The event payload received from Appwrite.
 * @returns Promise<void>
 */
export async function trigger(event: {
  type: string;
  userId: string;
  data: Record<string, unknown>;
}): Promise<void> {
  const { type, userId, data } = event;

  // Always fetch the full user object regardless of the update type.
  const user = await getUser(userId);

  // For consistency, merge any updated fields into the user object.
  // This ensures that the payload contains the latest state.
  const payload = {
    ...user,
    ...data,
  };

  // Send the event to the function runtime.
  await sendEvent(`users.${userId}.update`, payload);
}
