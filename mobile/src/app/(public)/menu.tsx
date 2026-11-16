import { StyleSheet, View } from "react-native";
import { router } from "expo-router";

import { AppText } from "../../components/AppText";
import { Button } from "../../components/Button";
import { Screen } from "../../components/Screen";
import { StoreStatusCard } from "../../components/StoreStatusCard";
import { colors } from "../../theme/colors";

/** "Explore the menu" lands here. The menu itself arrives in phase 6. */
export default function Menu() {
  return (
    <Screen>
      <View style={styles.block}>
        <AppText family="display" weight="bold" size={24}>
          Menu
        </AppText>
        <AppText color={colors.ink500}>The menu is coming in a later update.</AppText>
      </View>

      <StoreStatusCard />

      <Button label="Back" variant="outline" onPress={() => (router.canGoBack() ? router.back() : router.replace("/"))} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  block: { gap: 6, paddingTop: 12 },
});
